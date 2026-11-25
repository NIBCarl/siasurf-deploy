<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Enums\TimePeriod;
use App\Events\BookingChangeRequested;
use App\Events\BookingChangeResolved;
use App\Exceptions\BookingChangeException;
use App\Exceptions\BookingRequestException;
use App\Exceptions\SafetyViolationException;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\BookingChangeRequestedNotification;
use App\Notifications\BookingChangeResolvedNotification;
use App\Notifications\BookingRescheduledNotification;
use App\Notifications\BookingSpotChangedNotification;
use App\Notifications\RescheduleProposedNotification;
use App\Notifications\RescheduleResponseNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FR 1.9.4 - instructors may cancel or reschedule their own bookings.
 * Reschedules first notify the student and post the reason in booking chat;
 * the schedule only moves after the student agrees (and SISA approves if < 24h).
 */
class BookingChangeService
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected BookingCancellationService $cancellationService,
        protected ?BookingEventService $bookingEventService = null,
        protected ?ConversationService $conversationService = null,
    ) {
    }

    /**
     * File a cancellation request.
     */
    public function requestCancellation(
        Booking $booking,
        User $requester,
        ?string $reason = null,
        CancellationReason $cancellationReason = CancellationReason::Instructor
    ): BookingChangeRequest {
        $this->assertChangeable($booking);

        $allowedCause = in_array($cancellationReason, [
            CancellationReason::Instructor,
            CancellationReason::Weather,
        ], true) ? $cancellationReason : CancellationReason::Instructor;

        return DB::transaction(function () use ($booking, $requester, $reason, $allowedCause) {
            $changeRequest = $booking->changeRequests()->create([
                'requested_by' => $requester->id,
                'type' => ChangeRequestType::Cancellation,
                'status' => ChangeRequestStatus::Pending,
                'requires_admin_approval' => $booking->changeRequiresAdminApproval(),
                'reason' => $reason,
                'cancellation_reason' => $allowedCause,
                'original_date' => $booking->date,
                'original_time_period' => $booking->time_period?->value,
                'original_start_time' => $booking->start_time,
                'original_end_time' => $booking->end_time,
            ]);

            return $this->settle($changeRequest);
        });
    }

    /**
     * Propose a reschedule for a single booking.
     *
     * @param  array{date:string,start_time:string,time_period?:string,end_time?:string}  $newSlot
     */
    public function requestReschedule(Booking $booking, User $requester, array $newSlot, ?string $reason = null): BookingChangeRequest
    {
        return $this->requestBulkReschedule($booking, $requester, $newSlot, $reason, [$booking->id])->firstOrFail();
    }

    /**
     * Propose a reschedule for one booking or a group of sibling bookings
     * sharing the same session, creating linked proposals with a shared
     * `batch_id` (when > 1 booking) and individual private chat messages.
     *
     * @param  array{date:string,start_time:string,time_period?:string,end_time?:string}  $newSlot
     * @param  array<int, int>|null  $bookingIds
     * @return Collection<int, BookingChangeRequest>
     */
    public function requestBulkReschedule(
        Booking $primaryBooking,
        User $requester,
        array $newSlot,
        ?string $reason = null,
        ?array $bookingIds = null
    ): Collection {
        if ($primaryBooking->instructor_id !== $requester->id && ! $requester->hasRole('admin')) {
            throw new BookingChangeException('You are not authorized to reschedule this booking.');
        }

        $startTime = Carbon::parse($newSlot['start_time'])->format('H:i');
        $newDate = Carbon::parse($newSlot['date'])->format('Y-m-d');
        $timePeriod = ((int) substr($startTime, 0, 2)) < 12 ? 'morning' : 'afternoon';

        if (Carbon::parse($newDate)->isBefore(today())) {
            throw new BookingChangeException('The new lesson date must be today or later.');
        }

        $manualBlock = Availability::query()
            ->where('user_id', $primaryBooking->instructor_id)
            ->whereDate('specific_date', $newDate)
            ->where('time_period', $timePeriod)
            ->manualBlock()
            ->exists();

        if ($manualBlock) {
            throw new BookingChangeException('You blocked this date as personal time. Unblock it first, then reschedule.');
        }

        return DB::transaction(function () use ($primaryBooking, $requester, $newDate, $startTime, $timePeriod, $reason, $bookingIds) {
            User::query()->whereKey($primaryBooking->instructor_id)->lockForUpdate()->first();

            $lockedPrimary = Booking::with([
                'student',
                'instructor.instructorProfile.surfSpots',
                'surfSpot',
                'participants',
                'changeRequests',
            ])->whereKey($primaryBooking->id)->lockForUpdate()->firstOrFail();

            $sessionCluster = $this->availabilityService->overlappingSessionBookings($lockedPrimary);
            $clusterIds = $sessionCluster->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            if (! in_array($lockedPrimary->id, $clusterIds, true)) {
                $clusterIds[] = $lockedPrimary->id;
            }

            $lockedCluster = Booking::with([
                'student',
                'instructor.instructorProfile.surfSpots',
                'surfSpot',
                'participants',
                'changeRequests',
            ])->whereIn('id', $clusterIds)->lockForUpdate()->orderBy('id')->get();

            if ($bookingIds !== null && count($bookingIds) > 0) {
                $requestedIds = array_values(array_unique(array_filter(array_map('intval', $bookingIds), fn (int $id) => $id > 0)));
                $availableIds = $lockedCluster->pluck('id')->map(fn ($id) => (int) $id)->all();

                foreach ($requestedIds as $requestedId) {
                    if (! in_array($requestedId, $availableIds, true)) {
                        throw new BookingChangeException('One or more selected bookings do not belong to this shared session.');
                    }
                }

                $targets = $lockedCluster
                    ->filter(fn (Booking $b) => in_array((int) $b->id, $requestedIds, true))
                    ->values();
            } else {
                $targets = $lockedCluster->where('id', $lockedPrimary->id)->values();
            }

            if ($targets->isEmpty()) {
                throw new BookingChangeException('Select at least one booking to reschedule.');
            }

            foreach ($targets as $target) {
                $this->assertChangeable($target);
                $this->assertSafetyAndAuthorization($target);
            }

            $allUnchanged = $targets->every(function (Booking $b) use ($newDate, $startTime) {
                return $b->date?->format('Y-m-d') === $newDate
                    && $b->start_time?->format('H:i') === $startTime;
            });

            if ($allUnchanged) {
                throw new BookingChangeException('Please choose a different date or start time than the current schedule.');
            }

            $surfSpotId = $lockedPrimary->surf_spot_id ? (int) $lockedPrimary->surf_spot_id : null;
            $bookabilityReason = $this->availabilityService->groupStartBookabilityReason($targets, $newDate, $startTime, $surfSpotId);
            if ($bookabilityReason !== null) {
                throw new BookingChangeException($bookabilityReason);
            }

            $batchId = $targets->count() > 1 ? (string) Str::uuid() : null;
            $requiresAdminApproval = $targets->contains(fn (Booking $b) => $b->changeRequiresAdminApproval());
            $expiresAt = $this->computeProposalExpiresAt($targets, $newDate, $startTime);
            $conversationService = $this->conversationService ?? app(ConversationService::class);
            $created = collect();

            foreach ($targets as $target) {
                $durationHours = $target->resolvedDurationHours();
                $endTime = Carbon::createFromFormat('H:i', $startTime)
                    ->addHours($durationHours)
                    ->format('H:i');

                $changeRequest = $target->changeRequests()->create([
                    'batch_id' => $batchId,
                    'requested_by' => $requester->id,
                    'type' => ChangeRequestType::Reschedule,
                    'status' => ChangeRequestStatus::AwaitingStudent,
                    'requires_admin_approval' => $requiresAdminApproval,
                    'reason' => $reason,
                    'original_date' => $target->date,
                    'original_time_period' => $target->time_period?->value,
                    'original_start_time' => $target->start_time,
                    'original_end_time' => $target->end_time,
                    'new_date' => $newDate,
                    'new_time_period' => $timePeriod,
                    'new_start_time' => $startTime,
                    'new_end_time' => $endTime,
                    'student_notified_at' => now(),
                    'expires_at' => $expiresAt,
                ]);

                $conversationService->sendRescheduleReasonMessage($target, $requester, $changeRequest);
                $created->push($changeRequest);
            }

            DB::afterCommit(function () use ($created) {
                foreach ($created as $changeRequest) {
                    $freshRequest = $changeRequest->fresh(['booking.student', 'booking.instructor', 'booking.surfSpot', 'requestedBy']);
                    if (! $freshRequest) {
                        continue;
                    }

                    $freshRequest->booking?->student?->notify(new RescheduleProposedNotification($freshRequest));

                    try {
                        broadcast(new BookingChangeRequested($freshRequest))->toOthers();
                    } catch (\Throwable $exception) {
                        Log::warning('Failed to broadcast reschedule proposal.', [
                            'change_request_id' => $freshRequest->id,
                            'booking_id' => $freshRequest->booking_id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

            return $created;
        });
    }

    /**
     * Compute a proposal expiry deadline that never outlives the lesson start.
     *
     * @param  Collection<int, Booking>  $targets
     */
    private function computeProposalExpiresAt(Collection $targets, string $newDate, string $newStartTime): Carbon
    {
        $tz = config('app.timezone', 'Asia/Manila');
        $now = now($tz);
        $candidates = [$now->copy()->addHours(Booking::rescheduleProposalHours())];

        $earliestOriginal = $targets
            ->map(fn (Booking $b) => $b->lessonStartAt())
            ->filter()
            ->sort()
            ->first();

        if ($earliestOriginal) {
            $clampedOriginal = $earliestOriginal->copy()->subHour();
            if ($clampedOriginal->lessThanOrEqualTo($now->copy()->addMinutes(15))) {
                $clampedOriginal = $earliestOriginal->greaterThan($now->copy()->addMinutes(15))
                    ? $earliestOriginal->copy()
                    : $now->copy()->addMinutes(15);
            }
            $candidates[] = $clampedOriginal;
        }

        $proposedStart = Carbon::createFromFormat('Y-m-d H:i', "{$newDate} {$newStartTime}", $tz);
        if ($proposedStart) {
            $clampedProposed = $proposedStart->copy()->subHour();
            if ($clampedProposed->lessThanOrEqualTo($now->copy()->addMinutes(15))) {
                $clampedProposed = $proposedStart->greaterThan($now->copy()->addMinutes(15))
                    ? $proposedStart->copy()
                    : $now->copy()->addMinutes(15);
            }
            $candidates[] = $clampedProposed;
        }

        return collect($candidates)
            ->sortBy(fn (Carbon $dt) => $dt->getTimestamp())
            ->first()
            ->copy()
            ->timezone(config('app.timezone', 'UTC'));
    }

    /**
     * Student agrees to a proposed reschedule.
     *
     * Applies immediately when >= 24h notice; moves into SISA approval queue
     * when < 24h before the original lesson.
     */
    public function acceptRescheduleByStudent(Booking $booking, User $student, ?string $note = null): ?BookingChangeRequest
    {
        if ($booking->student_id !== $student->id) {
            throw new BookingChangeException('Only the booking student can respond to this reschedule.');
        }

        $proposal = $booking->changeRequests()
            ->reschedule()
            ->awaitingStudent()
            ->latest('id')
            ->first();

        if (! $proposal) {
            if ($booking->rescheduled_by_instructor_at !== null && $booking->student_accepted_reschedule_at === null) {
                $booking->update([
                    'student_accepted_reschedule_at' => now(),
                ]);

                return null;
            }

            throw new BookingChangeException('There is no pending reschedule to confirm.');
        }

        return DB::transaction(function () use ($booking, $proposal, $note) {
            User::query()->whereKey($booking->instructor_id)->lockForUpdate()->first();

            $lockedBooking = Booking::with(['instructor.instructorProfile.surfSpots', 'surfSpot', 'participants'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedProposal = BookingChangeRequest::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if (! $lockedProposal->isAwaitingStudent()) {
                throw new BookingChangeException('This reschedule request has already been handled.');
            }

            if ($lockedProposal->isExpired()) {
                $lockedProposal->resolve(
                    ChangeRequestStatus::Rejected,
                    null,
                    'Reschedule proposal expired before student responded.'
                );

                throw new BookingChangeException('This reschedule proposal has expired. Your booking keeps its original schedule.');
            }

            if (! $lockedBooking->canBeChanged()) {
                throw new BookingChangeException(
                    'This booking can no longer be changed because it is ' . $lockedBooking->status->label() . '.'
                );
            }

            $this->assertSafetyAndAuthorization($lockedBooking);

            $slot = [
                'date' => $lockedProposal->new_date->format('Y-m-d'),
                'time_period' => (string) $lockedProposal->new_time_period,
                'start_time' => $lockedProposal->new_start_time?->format('H:i') ?? '08:00',
                'end_time' => $lockedProposal->new_end_time?->format('H:i') ?? '10:00',
            ];

            $this->assertSlotIsFree($lockedBooking, $slot);

            $lockedProposal->update([
                'student_agreed_at' => now(),
                'student_response_note' => $note,
            ]);

            if ($lockedProposal->requires_admin_approval) {
                $lockedProposal->update([
                    'status' => ChangeRequestStatus::Pending,
                ]);

                $this->notifyAdmins($lockedProposal);

                DB::afterCommit(function () use ($lockedProposal) {
                    $fresh = $lockedProposal->fresh(['booking.student', 'booking.instructor', 'booking.surfSpot', 'requestedBy']);

                    if ($fresh) {
                        $fresh->booking?->instructor?->notify(new RescheduleResponseNotification($fresh, agreed: true, awaitingAdmin: true));

                        try {
                            broadcast(new BookingChangeRequested($fresh))->toOthers();
                        } catch (\Throwable $exception) {
                            Log::warning('Failed to broadcast reschedule awaiting admin.', [
                                'change_request_id' => $fresh->id,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }
                });

                return $lockedProposal->fresh();
            }

            $lockedProposal->resolve(ChangeRequestStatus::AutoApproved);
            $this->apply($lockedProposal);

            DB::afterCommit(function () use ($lockedProposal) {
                $fresh = $lockedProposal->fresh(['booking.student', 'booking.instructor', 'booking.surfSpot', 'requestedBy']);

                if ($fresh) {
                    $fresh->booking?->instructor?->notify(new RescheduleResponseNotification($fresh, agreed: true, awaitingAdmin: false));

                    try {
                        broadcast(new BookingChangeResolved($fresh))->toOthers();
                    } catch (\Throwable $exception) {
                        Log::warning('Failed to broadcast reschedule resolution.', [
                            'change_request_id' => $fresh->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

            return $lockedProposal->fresh();
        });
    }

    /**
     * Student declines a proposed reschedule, keeping the original slot.
     */
    public function declineRescheduleByStudent(Booking $booking, User $student, ?string $note = null): BookingChangeRequest
    {
        if ($booking->student_id !== $student->id) {
            throw new BookingChangeException('Only the booking student can respond to this reschedule.');
        }

        $proposal = $booking->pendingRescheduleProposal();

        if (! $proposal) {
            throw new BookingChangeException('There is no pending reschedule to decline.');
        }

        return DB::transaction(function () use ($proposal, $note) {
            $proposal->update([
                'status' => ChangeRequestStatus::Rejected,
                'student_declined_at' => now(),
                'student_response_note' => $note,
                'reviewed_at' => now(),
            ]);

            DB::afterCommit(function () use ($proposal) {
                $fresh = $proposal->fresh(['booking.student', 'booking.instructor', 'booking.surfSpot', 'requestedBy']);

                if ($fresh) {
                    $fresh->booking?->instructor?->notify(new RescheduleResponseNotification($fresh, agreed: false, awaitingAdmin: false));

                    try {
                        broadcast(new BookingChangeResolved($fresh))->toOthers();
                    } catch (\Throwable $exception) {
                        Log::warning('Failed to broadcast declined reschedule.', [
                            'change_request_id' => $fresh->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

            return $proposal->fresh();
        });
    }

    /**
     * Auto-apply when no approval is needed, otherwise queue it for SISA.
     */
    private function settle(BookingChangeRequest $changeRequest): BookingChangeRequest
    {
        if ($changeRequest->requires_admin_approval) {
            $this->notifyAdmins($changeRequest);

            broadcast(new BookingChangeRequested(
                $changeRequest->fresh(['booking.student', 'booking.instructor', 'requestedBy'])
            ))->toOthers();

            return $changeRequest;
        }

        $changeRequest->resolve(ChangeRequestStatus::AutoApproved);
        $this->apply($changeRequest);

        broadcast(new BookingChangeResolved(
            $changeRequest->fresh(['booking.student', 'booking.instructor', 'requestedBy'])
        ))->toOthers();

        return $changeRequest;
    }

    /**
     * Admin approves a late change and it is applied immediately.
     */
    public function approve(BookingChangeRequest $changeRequest, User $admin, ?string $note = null): BookingChangeRequest
    {
        return DB::transaction(function () use ($changeRequest, $admin, $note) {
            $lockedRequest = BookingChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->isOpen()) {
                throw new BookingChangeException('This request has already been resolved.');
            }

            if ($lockedRequest->isReschedule() && $lockedRequest->student_agreed_at === null) {
                throw new BookingChangeException('The student must be notified and agree to the reschedule before it can be approved.');
            }

            if (! $lockedRequest->isPending()) {
                throw new BookingChangeException('This request has already been resolved.');
            }

            $instructorId = Booking::query()->whereKey($lockedRequest->booking_id)->value('instructor_id');
            if ($instructorId) {
                User::query()->whereKey($instructorId)->lockForUpdate()->first();
            }

            $lockedBooking = Booking::with(['instructor.instructorProfile', 'surfSpot', 'participants'])
                ->whereKey($lockedRequest->booking_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedBooking->canBeChanged()) {
                throw new BookingChangeException(
                    'This booking can no longer be changed because it is ' . $lockedBooking->status->label() . '.'
                );
            }

            if (! $lockedRequest->isCancellation()) {
                $this->assertSafetyAndAuthorization($lockedBooking);

                $this->assertSlotIsFree($lockedBooking, [
                    'date' => $lockedRequest->new_date->format('Y-m-d'),
                    'time_period' => $lockedRequest->new_time_period instanceof \BackedEnum
                        ? (string) $lockedRequest->new_time_period->value
                        : (string) $lockedRequest->new_time_period,
                    'start_time' => $lockedRequest->new_start_time->format('H:i'),
                    'end_time' => $lockedRequest->new_end_time->format('H:i'),
                ]);
            }

            $lockedRequest->setRelation('booking', $lockedBooking);
            $lockedRequest->resolve(ChangeRequestStatus::Approved, $admin->id, $note);
            $this->apply($lockedRequest);

            DB::afterCommit(function () use ($lockedRequest, $admin) {
                broadcast(new BookingChangeResolved(
                    $lockedRequest->fresh(['booking.student', 'booking.instructor', 'requestedBy'])
                ))->toOthers();

                $this->notifyRequester($lockedRequest, $admin);
            });

            return $lockedRequest->fresh();
        });
    }

    /**
     * Admin rejects a late change; the booking keeps its original slot.
     */
    public function reject(BookingChangeRequest $changeRequest, User $admin, ?string $note = null): BookingChangeRequest
    {
        if (!$changeRequest->isPending()) {
            throw new BookingChangeException('This request has already been resolved.');
        }

        return DB::transaction(function () use ($changeRequest, $admin, $note) {
            $changeRequest->resolve(ChangeRequestStatus::Rejected, $admin->id, $note);

            broadcast(new BookingChangeResolved(
                $changeRequest->fresh(['booking.student', 'booking.instructor', 'requestedBy'])
            ))->toOthers();

            $this->notifyRequester($changeRequest, $admin);

            return $changeRequest;
        });
    }

    /**
     * The requester pulls their own request back before SISA / student acts on it.
     */
    public function withdraw(BookingChangeRequest $changeRequest, User $requester): BookingChangeRequest
    {
        return DB::transaction(function () use ($changeRequest, $requester) {
            $lockedRequest = BookingChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->isOpen()) {
                throw new BookingChangeException('This request has already been resolved.');
            }

            if ($lockedRequest->requested_by !== $requester->id && ! $requester->hasRole('admin')) {
                throw new BookingChangeException('Only the instructor who filed the request can withdraw it.');
            }

            $lockedRequest->resolve(ChangeRequestStatus::Rejected, null, 'Withdrawn by the instructor.');

            DB::afterCommit(function () use ($lockedRequest, $requester) {
                $fresh = $lockedRequest->fresh(['booking.student', 'booking.instructor', 'requestedBy']);
                if (! $fresh) {
                    return;
                }

                try {
                    broadcast(new BookingChangeResolved($fresh))->toOthers();
                } catch (\Throwable $exception) {
                    Log::warning('Failed to broadcast withdrawn change request.', [
                        'change_request_id' => $fresh->id,
                        'error' => $exception->getMessage(),
                    ]);
                }

                $this->notifyRequester($fresh, $requester);

                if ($fresh->isReschedule() && $fresh->student_notified_at !== null) {
                    $fresh->booking?->student?->notify(new BookingChangeResolvedNotification($fresh));
                }
            });

            return $lockedRequest->fresh();
        });
    }

    /**
     * Withdraw every open change request belonging to the same batch.
     *
     * @return Collection<int, BookingChangeRequest>
     */
    public function withdrawBatch(string $batchId, User $requester): Collection
    {
        return DB::transaction(function () use ($batchId, $requester) {
            $requests = BookingChangeRequest::query()
                ->forBatch($batchId)
                ->open()
                ->lockForUpdate()
                ->get();

            if ($requests->isEmpty()) {
                throw new BookingChangeException('There are no open requests in this batch to withdraw.');
            }

            $withdrawn = collect();

            foreach ($requests as $item) {
                if ($item->requested_by !== $requester->id && ! $requester->hasRole('admin')) {
                    throw new BookingChangeException('Only the instructor who filed the request can withdraw it.');
                }

                $item->resolve(ChangeRequestStatus::Rejected, null, 'Withdrawn by the instructor.');
                $withdrawn->push($item);
            }

            DB::afterCommit(function () use ($withdrawn, $requester) {
                foreach ($withdrawn as $item) {
                    $fresh = $item->fresh(['booking.student', 'booking.instructor', 'requestedBy']);
                    if (! $fresh) {
                        continue;
                    }

                    try {
                        broadcast(new BookingChangeResolved($fresh))->toOthers();
                    } catch (\Throwable $exception) {
                        Log::warning('Failed to broadcast withdrawn batch request.', [
                            'change_request_id' => $fresh->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }

                    $this->notifyRequester($fresh, $requester);

                    if ($fresh->isReschedule() && $fresh->student_notified_at !== null) {
                        $fresh->booking?->student?->notify(new BookingChangeResolvedNotification($fresh));
                    }
                }
            });

            return $withdrawn;
        });
    }

    /**
     * Approve all student-agreed pending requests in a shared batch.
     *
     * @return array{approved: int, skipped: int, errors: array<int, string>}
     */
    public function approveBatch(string $batchId, User $admin, ?string $note = null): array
    {
        $pending = BookingChangeRequest::query()
            ->forBatch($batchId)
            ->pending()
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            throw new BookingChangeException('There are no pending student-agreed requests in this batch.');
        }

        $approved = 0;
        $skipped = 0;
        $errors = [];

        foreach ($pending as $changeRequest) {
            try {
                $this->approve($changeRequest, $admin, $note);
                $approved++;
            } catch (BookingChangeException $exception) {
                $skipped++;
                $errors[] = "Booking #{$changeRequest->booking_id}: {$exception->getMessage()}";
            }
        }

        if ($approved === 0 && ! empty($errors)) {
            throw new BookingChangeException($errors[0]);
        }

        return [
            'approved' => $approved,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Reject all pending requests in a shared batch.
     */
    public function rejectBatch(string $batchId, User $admin, ?string $note = null): int
    {
        $pending = BookingChangeRequest::query()
            ->forBatch($batchId)
            ->pending()
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            throw new BookingChangeException('There are no pending requests in this batch to reject.');
        }

        $rejected = 0;

        foreach ($pending as $changeRequest) {
            if (! $changeRequest->isPending()) {
                continue;
            }

            $this->reject($changeRequest, $admin, $note);
            $rejected++;
        }

        return $rejected;
    }

    /**
     * Expire unanswered reschedule proposals whose response window has elapsed.
     */
    public function expireStaleProposals(?int $instructorId = null): int
    {
        $ids = BookingChangeRequest::query()
            ->expiredProposals()
            ->when($instructorId !== null, fn ($q) => $q->whereHas('booking', fn ($b) => $b->where('instructor_id', $instructorId)))
            ->pluck('id');

        $expiredCount = 0;

        foreach ($ids as $id) {
            $didExpire = DB::transaction(function () use ($id): bool {
                $locked = BookingChangeRequest::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked || ! $locked->isExpired()) {
                    return false;
                }

                $locked->resolve(
                    ChangeRequestStatus::Rejected,
                    null,
                    'Reschedule proposal expired before student responded.'
                );

                DB::afterCommit(function () use ($locked) {
                    $fresh = $locked->fresh(['booking.student', 'booking.instructor', 'requestedBy']);
                    if (! $fresh) {
                        return;
                    }

                    try {
                        broadcast(new BookingChangeResolved($fresh))->toOthers();
                    } catch (\Throwable $exception) {
                        Log::warning('Failed to broadcast expired reschedule proposal.', [
                            'change_request_id' => $fresh->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }

                    $this->notifyRequester($fresh, null);
                });

                return true;
            });

            if ($didExpire) {
                $expiredCount++;
            }
        }

        return $expiredCount;
    }

    /**
     * Summarize a shared reschedule batch for instructor & admin dashboards.
     *
     * @return array<string, mixed>|null
     */
    public function summarizeBatch(string $batchId): ?array
    {
        $requests = BookingChangeRequest::with(['booking.student'])
            ->forBatch($batchId)
            ->reschedule()
            ->orderBy('id')
            ->get();

        if ($requests->isEmpty()) {
            return null;
        }

        $agreedCount = 0;
        $awaitingStudentCount = 0;
        $awaitingAdminCount = 0;
        $declinedCount = 0;
        $cancelledCount = 0;
        $expiredCount = 0;
        $openCount = 0;
        $movedOrAgreedCount = 0;
        $activeDeclineSplitCount = 0;
        $members = [];

        foreach ($requests as $req) {
            $booking = $req->booking;
            $bookingStatus = $booking?->status;

            $isCancelledBooking = $bookingStatus !== null && in_array($bookingStatus, [
                BookingStatus::Cancelled,
                BookingStatus::Declined,
                BookingStatus::Expired,
                BookingStatus::PaymentTimeout,
            ], true);

            $state = match (true) {
                $isCancelledBooking => 'cancelled',
                $req->applied_at !== null || in_array($req->status, [ChangeRequestStatus::Approved, ChangeRequestStatus::AutoApproved], true) => 'moved',
                $req->isPending() => 'awaiting_admin',
                $req->isExpired() => 'expired',
                $req->isAwaitingStudent() => 'awaiting_student',
                $req->student_declined_at !== null => 'declined',
                default => 'closed',
            };

            if ($state === 'moved') {
                $agreedCount++;
                $movedOrAgreedCount++;
            } elseif ($state === 'awaiting_admin') {
                $agreedCount++;
                $awaitingAdminCount++;
                $openCount++;
                $movedOrAgreedCount++;
            } elseif ($state === 'awaiting_student') {
                $awaitingStudentCount++;
                $openCount++;
            } elseif ($state === 'declined') {
                $declinedCount++;
                $activeDeclineSplitCount++;
            } elseif ($state === 'cancelled') {
                $cancelledCount++;
            } elseif ($state === 'expired') {
                $expiredCount++;
            }

            $members[] = [
                'booking_id' => $req->booking_id,
                'booking_ref' => '#SURF-' . str_pad((string) $req->booking_id, 5, '0', STR_PAD_LEFT),
                'student_name' => $booking?->student?->name ?? 'Student',
                'booking_status' => $bookingStatus?->value,
                'request_id' => $req->id,
                'request_status' => $req->status?->value,
                'state' => $state,
                'show_url' => route('instructor.bookings.show', $req->booking_id),
            ];
        }

        /** @var BookingChangeRequest $sample */
        $sample = $requests->first();

        return [
            'batch_id' => $batchId,
            'total' => $requests->count(),
            'agreed_count' => $agreedCount,
            'awaiting_student_count' => $awaitingStudentCount,
            'awaiting_admin_count' => $awaitingAdminCount,
            'declined_count' => $declinedCount,
            'cancelled_count' => $cancelledCount,
            'expired_count' => $expiredCount,
            'open_count' => $openCount,
            'has_schedule_split' => $movedOrAgreedCount > 0 && $activeDeclineSplitCount > 0,
            'new_date' => $sample->new_date?->format('Y-m-d'),
            'new_time_range' => $sample->describeTimeRange(
                $sample->new_start_time,
                $sample->new_end_time,
                $sample->new_time_period
            ),
            'expires_at' => $sample->expires_at?->toIso8601String(),
            'expires_in_seconds' => $requests->max(fn (BookingChangeRequest $r) => $r->getExpiryTimeRemaining()) ?? 0,
            'members' => $members,
        ];
    }

    /**
     * Persist the outcome for the requester when another user decided it.
     * The broadcast above is the live nudge; this is what the bell shows
     * after a page reload.
     */
    private function notifyRequester(BookingChangeRequest $changeRequest, ?User $actor = null): void
    {
        $fresh = $changeRequest->fresh(['booking.instructor', 'requestedBy']);

        if (! $fresh) {
            return;
        }

        $recipient = $fresh->requestedBy ?? $fresh->booking?->instructor;

        if ($recipient && ($actor === null || $actor->id !== $recipient->id)) {
            $recipient->notify(new BookingChangeResolvedNotification($fresh));
        }
    }

    /**
     * Push an approved change onto the booking itself.
     */
    private function apply(BookingChangeRequest $changeRequest): void
    {
        $booking = $changeRequest->booking;

        if (!$booking) {
            return;
        }

        if ($changeRequest->isCancellation()) {
            // The student must see the instructor's explanation, plus any note
            // SISA added when approving the late cancellation.
            $note = trim((string) $changeRequest->reason);

            if ($changeRequest->admin_note) {
                $note = trim(
                    $note
                    . ($note !== '' ? "\n\n" : '')
                    . 'SISA note: ' . $changeRequest->admin_note
                );
            }

            if ($note === '') {
                $note = 'The instructor cancelled this lesson without providing a reason.';
            }

            $reason = $changeRequest->cancellation_reason ?? CancellationReason::Instructor;
            $actor = $changeRequest->reviewedBy ?? $changeRequest->requestedBy;

            $this->cancellationService->cancel($booking, $reason, $note, $actor, notifyInstructor: false);
        } else {
            $this->applyReschedule($changeRequest, $booking);
        }

        $changeRequest->markApplied();
    }

    /**
     * Move a booking to the requested slot and relocate its availability lock.
     */
    private function applyReschedule(BookingChangeRequest $changeRequest, Booking $booking): void
    {
        $oldDate = $this->availabilityService->dateOf($booking);
        $oldPeriod = $this->availabilityService->timePeriodOf($booking);

        $booking->update([
            'original_date' => $booking->original_date ?? $booking->date,
            'date' => $changeRequest->new_date,
            'time_period' => $changeRequest->new_time_period,
            'start_time' => $changeRequest->new_start_time?->format('H:i'),
            'end_time' => $changeRequest->new_end_time?->format('H:i'),
            'duration_hours' => $booking->resolvedDurationHours(),
            'rescheduled_by_instructor_at' => now(),
            'student_accepted_reschedule_at' => $changeRequest->student_agreed_at ?? now(),
        ]);

        $booking->refresh();

        // FR 1.8.3 - old slot is freed, new slot is locked.
        $this->availabilityService->moveBookingSlot($booking, $oldDate, $oldPeriod);

        ($this->bookingEventService ?? app(BookingEventService::class))
            ->recordRescheduled(
                $booking,
                $changeRequest,
                $changeRequest->reviewed_by ?? $changeRequest->requested_by
            );

        DB::afterCommit(function () use ($booking, $changeRequest) {
            $fresh = $booking->fresh(['instructor', 'surfSpot', 'student']);
            $fresh?->student?->notify(new BookingRescheduledNotification(
                $fresh,
                $changeRequest->reason,
                $changeRequest->admin_note
            ));
        });
    }

    /**
     * Every SISA account hears about a change that needs its sign-off.
     */
    private function notifyAdmins(BookingChangeRequest $changeRequest): void
    {
        User::query()->admins()->get()->each(
            fn (User $admin) => $admin->notify(new BookingChangeRequestedNotification($changeRequest))
        );
    }

    /**
     * Re-check instructor service/spot authorization and safety rules before
     * proposing, accepting, or approving a reschedule.
     */
    private function assertSafetyAndAuthorization(Booking $booking): void
    {
        $booking->loadMissing(['instructor.instructorProfile.surfSpots', 'surfSpot', 'participants']);
        $profile = $booking->instructor?->instructorProfile;

        if (! $profile) {
            return;
        }

        $service = $booking->session_type instanceof SessionType
            ? $booking->session_type
            : SessionType::tryFrom((string) $booking->session_type);

        if ($service && ! $profile->offersService($service)) {
            throw new BookingChangeException('The instructor no longer offers this service type.');
        }

        if ($booking->surfSpot && ! $profile->teachesAtSurfSpot($booking->surfSpot)) {
            throw new BookingChangeException('The instructor is no longer authorized to teach at the scheduled surf spot.');
        }

        $safetyParticipants = $booking->participants->isNotEmpty()
            ? $booking->participants->map(fn ($p) => [
                'age' => $p->birthday?->age ?? $p->age,
                'skill_level' => $p->skill_level,
            ])->values()->all()
            : [[
                'age' => $booking->student_age,
                'skill_level' => $booking->skill_level,
            ]];

        try {
            app(SafetyService::class)->validateParticipants(
                $safetyParticipants,
                (int) ($profile->level?->value ?? 1),
                (string) ($booking->surfSpot?->difficulty ?? 'beginner'),
                $booking->student_id,
                $service
            );
        } catch (SafetyViolationException $exception) {
            throw new BookingChangeException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Guard the states where a change no longer makes sense.
     */
    private function assertChangeable(Booking $booking): void
    {
        if (!$booking->canBeChanged()) {
            throw new BookingChangeException(
                'This booking can no longer be changed because it is ' . $booking->status->label() . '.'
            );
        }

        if ($booking->hasPendingChangeRequest()) {
            throw new BookingChangeException('A change request for this booking is already awaiting review.');
        }
    }

    /**
     * Make sure the requested slot is actually bookable.
     *
     * @param  array{date:string,time_period:string,start_time:string,end_time:string}  $slot
     */
    private function assertSlotIsFree(Booking $booking, array $slot): void
    {
        if (Carbon::parse($slot['date'])->isBefore(today())) {
            throw new BookingChangeException('The new lesson date must be today or later.');
        }

        $manualBlock = Availability::query()
            ->where('user_id', $booking->instructor_id)
            ->whereDate('specific_date', $slot['date'])
            ->where('time_period', $slot['time_period'])
            ->manualBlock()
            ->exists();

        if ($manualBlock) {
            throw new BookingChangeException('You blocked this date as personal time. Unblock it first, then reschedule.');
        }

        // Capacity-aware: another lesson may share the slot as long as the
        // instructor's student capacity, skill level, service type, and spot
        // are compatible. The booking being moved is excluded so it never
        // conflicts with itself.
        $bookingSkill = $booking->skill_level instanceof SkillLevel
            ? $booking->skill_level
            : SkillLevel::tryFrom((string) $booking->skill_level);
        $bookingService = $booking->session_type instanceof SessionType
            ? $booking->session_type
            : SessionType::tryFrom((string) $booking->session_type);

        try {
            $this->availabilityService->assertRangeIsBookable(
                $booking->instructor_id,
                $slot['date'],
                $slot['start_time'],
                $slot['end_time'],
                max(1, (int) $booking->student_count),
                $booking->id,
                $bookingSkill,
                $bookingService,
                $booking->surf_spot_id ? (int) $booking->surf_spot_id : null
            );
        } catch (BookingRequestException $exception) {
            throw new BookingChangeException($exception->getMessage());
        }
    }

    /**
     * Move this booking (and any overlapping bookings sharing the same instructor
     * session) to a new surf spot and notify every affected student with the
     * instructor's reason.
     *
     * Safety Rule #5 is never bypassed: the new spot's difficulty must remain
     * safe for every surfer in the session and be offered by the instructor.
     *
     * @return Collection<int, Booking>
     */
    public function updateSurfSpot(Booking $booking, SurfSpot $newSpot, string $reason, User $instructor): Collection
    {
        if ($booking->instructor_id !== $instructor->id) {
            throw new BookingChangeException('You are not authorized to change the surf spot for this booking.');
        }

        if (! $booking->canChangeSurfSpot()) {
            throw new BookingChangeException(
                'The surf spot can no longer be updated because this booking is ' . $booking->status->label() . '.'
            );
        }

        if (! $newSpot->is_active) {
            throw new BookingChangeException('The selected surf spot is currently unavailable.');
        }

        if ($newSpot->id === $booking->surf_spot_id) {
            throw new BookingChangeException("This lesson is already scheduled at {$newSpot->name}.");
        }

        $profile = $instructor->instructorProfile?->loadMissing('surfSpots');

        if (! $profile || ! $profile->teachesAtSurfSpot($newSpot) || ! $profile->level->canTeachAtDifficulty((string) $newSpot->difficulty)) {
            throw new BookingChangeException("You are not authorized to teach at {$newSpot->name}.");
        }

        $cleanReason = trim($reason);

        if ($cleanReason === '') {
            throw new BookingChangeException('Please provide a reason for changing the surf spot.');
        }

        return DB::transaction(function () use ($booking, $newSpot, $cleanReason, $instructor) {
            User::query()->whereKey($instructor->id)->lockForUpdate()->first();

            $lockedBooking = Booking::with('surfSpot')
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedBooking->canChangeSurfSpot()) {
                throw new BookingChangeException(
                    'The surf spot can no longer be updated because this booking is ' . $lockedBooking->status->label() . '.'
                );
            }

            $group = $this->availabilityService->overlappingSessionBookings($lockedBooking);

            foreach ($group as $member) {
                $memberSkill = $member->skill_level instanceof SkillLevel
                    ? $member->skill_level
                    : (SkillLevel::tryFrom((string) $member->skill_level) ?? SkillLevel::Beginner);

                if (! $memberSkill->canSurfDifficulty((string) $newSpot->difficulty)) {
                    throw new BookingChangeException(sprintf(
                        'Safety rule violation: %s surfers in this session cannot surf %s breaks (%s is rated %s).',
                        $memberSkill->label(),
                        $newSpot->difficulty,
                        $newSpot->name,
                        ucfirst((string) $newSpot->difficulty)
                    ));
                }
            }

            $previousSpots = [];

            foreach ($group as $member) {
                $previousSpots[$member->id] = $member->surfSpot?->name ?? 'Previous surf spot';

                $member->update([
                    'previous_surf_spot_id' => $member->surf_spot_id,
                    'surf_spot_id' => $newSpot->id,
                    'spot_change_reason' => $cleanReason,
                    'spot_changed_at' => now(),
                ]);
            }

            DB::afterCommit(function () use ($group, $previousSpots, $newSpot, $cleanReason) {
                foreach ($group as $member) {
                    $fresh = $member->fresh(['instructor', 'surfSpot', 'student', 'previousSurfSpot']);
                    $fresh?->student?->notify(new BookingSpotChangedNotification(
                        $fresh,
                        $previousSpots[$member->id],
                        $newSpot->name,
                        $cleanReason
                    ));
                }
            });

            return $group;
        });
    }
}