<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\InstructorLevel;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundBasis;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\Payment;
use App\Models\SurfSpot;
use App\Models\User;
use App\Notifications\BookingChangeResolvedNotification;
use App\Notifications\BookingRescheduledNotification;
use App\Notifications\RescheduleProposedNotification;
use App\Services\AvailabilityService;
use App\Services\BookingChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BulkRescheduleFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;
    private User $admin;
    private SurfSpot $spotA;
    private SurfSpot $spotB;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('student', 'web');
        Role::findOrCreate('instructor', 'web');
        Role::findOrCreate('admin', 'web');

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level3,
            'status' => 'active',
            'rate_per_hour' => 800,
            'guiding_rate' => 1000,
            'coaching_rate' => 1600,
        ]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('admin');

        $this->spotA = SurfSpot::factory()->create(['name' => 'Cloud 9', 'difficulty' => 'beginner']);
        $this->spotB = SurfSpot::factory()->create(['name' => 'Jacking Horse', 'difficulty' => 'beginner']);
    }

    private function makeStudent(string $name): User
    {
        $student = User::factory()->create([
            'name' => $name,
            'role' => 'student',
        ]);
        $student->assignRole('student');

        return $student;
    }

    private function makeBooking(User $student, array $overrides = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'student_id' => $student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $this->spotA->id,
            'status' => BookingStatus::Confirmed,
            'skill_level' => SkillLevel::Beginner->value,
            'session_type' => SessionType::Teaching->value,
            'student_count' => 1,
            'student_age' => 24,
            'date' => now()->addDays(4)->format('Y-m-d'),
            'time_period' => 'morning',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'hourly_rate' => 800,
            'lesson_price' => 1600,
            'service_fee_percent' => 7.00,
            'service_fee_amount' => 112.00,
            'total_amount' => 1712.00,
            'payment_status' => PaymentStatus::Completed,
        ], $overrides));
    }

    /** @test */
    public function level3_bulk_reschedule_creates_batch_notifies_all_three_students_and_isolates_private_chats(): void
    {
        Notification::fake();

        $s1 = $this->makeStudent('Alice Surfer');
        $s2 = $this->makeStudent('Bob Wave');
        $s3 = $this->makeStudent('Cara Tide');

        $b1 = $this->makeBooking($s1);
        $b2 = $this->makeBooking($s2);
        $b3 = $this->makeBooking($s3);

        $newDate = now()->addDays(6)->format('Y-m-d');

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $b1->id), [
                'type' => 'reschedule',
                'date' => $newDate,
                'start_time' => '09:00',
                'reason' => 'Cleaner morning swell window at Cloud 9.',
                'booking_ids' => [$b1->id, $b2->id, $b3->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $requests = BookingChangeRequest::query()->whereIn('booking_id', [$b1->id, $b2->id, $b3->id])->get();
        $this->assertCount(3, $requests);

        $batchIds = $requests->pluck('batch_id')->filter()->unique();
        $this->assertCount(1, $batchIds);
        $this->assertTrue($requests->every(fn ($r) => $r->status === ChangeRequestStatus::AwaitingStudent && $r->expires_at !== null));

        Notification::assertSentTo($s1, RescheduleProposedNotification::class);
        Notification::assertSentTo($s2, RescheduleProposedNotification::class);
        Notification::assertSentTo($s3, RescheduleProposedNotification::class);

        // Private 1:1 chat receives the instructor's reason without leaking sibling student names.
        foreach ([[$b1, $s2->name, $s3->name], [$b2, $s1->name, $s3->name], [$b3, $s1->name, $s2->name]] as [$booking, $otherA, $otherB]) {
            $body = $booking->conversation()->firstOrFail()->messages()->latest('id')->firstOrFail()->body;
            $this->assertStringContainsString('Cleaner morning swell window at Cloud 9.', $body);
            $this->assertStringNotContainsString($otherA, $body);
            $this->assertStringNotContainsString($otherB, $body);
        }

        // All 3 students can accept sequentially and move to the new slot.
        foreach ([[$s1, $b1], [$s2, $b2], [$s3, $b3]] as [$student, $booking]) {
            $this->actingAs($student)
                ->post(route('student.bookings.accept-reschedule', $booking->id))
                ->assertRedirect()
                ->assertSessionHas('success');

            $booking->refresh();
            $this->assertSame($newDate, $booking->date->format('Y-m-d'));
            $this->assertSame('09:00', $booking->start_time->format('H:i'));
            $this->assertSame('11:00', $booking->end_time->format('H:i'));
        }

        Notification::assertSentTo($s1, BookingRescheduledNotification::class);
        Notification::assertSentTo($s2, BookingRescheduledNotification::class);
        Notification::assertSentTo($s3, BookingRescheduledNotification::class);
    }

    /** @test */
    public function same_day_overlapping_shift_succeeds_for_all_three_siblings_without_self_conflict(): void
    {
        $s1 = $this->makeStudent('Surfer One');
        $s2 = $this->makeStudent('Surfer Two');
        $s3 = $this->makeStudent('Surfer Three');

        $date = now()->addDays(4)->format('Y-m-d');
        $b1 = $this->makeBooking($s1, ['date' => $date, 'start_time' => '08:00', 'end_time' => '10:00']);
        $b2 = $this->makeBooking($s2, ['date' => $date, 'start_time' => '08:00', 'end_time' => '10:00']);
        $b3 = $this->makeBooking($s3, ['date' => $date, 'start_time' => '08:00', 'end_time' => '10:00']);

        $availResponse = $this->actingAs($this->instructor)
            ->getJson(route('instructor.bookings.reschedule-availability', [
                'booking' => $b1->id,
                'date' => $date,
                'booking_ids' => [$b1->id, $b2->id, $b3->id],
            ]))
            ->assertOk()
            ->json();

        $morningStarts = collect($availResponse['periods']['morning']['slots'] ?? [])
            ->pluck('start_time')
            ->all();
        $this->assertContains('09:00', $morningStarts);

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $b1->id), [
                'type' => 'reschedule',
                'date' => $date,
                'start_time' => '09:00',
                'reason' => 'Shift by 1 hour for tide.',
                'booking_ids' => [$b1->id, $b2->id, $b3->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        foreach ([[$s1, $b1], [$s2, $b2], [$s3, $b3]] as [$student, $booking]) {
            $this->actingAs($student)
                ->post(route('student.bookings.accept-reschedule', $booking->id))
                ->assertRedirect()
                ->assertSessionHas('success');

            $booking->refresh();
            $this->assertSame('09:00', $booking->start_time->format('H:i'));
            $this->assertSame('11:00', $booking->end_time->format('H:i'));
        }
    }

    /** @test */
    public function virtual_soft_hold_blocks_outsider_while_proposal_is_open_and_releases_when_withdrawn_or_expired(): void
    {
        Notification::fake();

        $s1 = $this->makeStudent('Group One');
        $s2 = $this->makeStudent('Group Two');
        $s3 = $this->makeStudent('Group Three');

        $b1 = $this->makeBooking($s1);
        $b2 = $this->makeBooking($s2);
        $b3 = $this->makeBooking($s3);

        $targetDate = now()->addDays(7)->format('Y-m-d');

        $this->actingAs($this->instructor)
            ->post(route('instructor.bookings.change-request', $b1->id), [
                'type' => 'reschedule',
                'date' => $targetDate,
                'start_time' => '09:00',
                'reason' => 'Better tide.',
                'booking_ids' => [$b1->id, $b2->id, $b3->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $availability = app(AvailabilityService::class);
        $this->assertSame(
            0,
            $availability->remainingCapacity($this->instructor->id, $targetDate, '09:00', '11:00')
        );

        // Also check calendar surf_spot_id awareness when target slot holds a different beach (spotA vs spotB).
        $daysMap = $availability->availabilityByDay(
            $this->instructor->id,
            $targetDate,
            1,
            1,
            null,
            SkillLevel::Beginner,
            SessionType::Teaching,
            $this->spotB->id
        );
        // Other hours in morning like 06:00-08:00 are still open at spotB, while 09:00-11:00 at spotB has 0 capacity.
        $this->assertSame(
            0,
            $availability->remainingCapacity($this->instructor->id, $targetDate, '09:00', '11:00', null, SkillLevel::Beginner, SessionType::Teaching, $this->spotB->id)
        );
        $this->assertTrue($daysMap[$targetDate]['morning']['available']);

        // Batch withdraw closes all 3 requests, preserves audit rows, notifies students, and frees the target slot.
        $this->actingAs($this->instructor)
            ->delete(route('instructor.bookings.change-request.cancel', $b1->id), [
                'scope' => 'batch',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, BookingChangeRequest::query()->open()->count());
        $this->assertSame(3, BookingChangeRequest::query()->where('status', ChangeRequestStatus::Rejected->value)->count());
        $this->assertSame(
            3,
            $availability->remainingCapacity($this->instructor->id, $targetDate, '09:00', '11:00')
        );

        Notification::assertSentTo($s1, BookingChangeResolvedNotification::class);
        Notification::assertSentTo($s2, BookingChangeResolvedNotification::class);
        Notification::assertSentTo($s3, BookingChangeResolvedNotification::class);
    }

    /** @test */
    public function partial_acceptance_flags_schedule_split_and_declining_student_retains_100_percent_refund_if_cancelled_later(): void
    {
        $s1 = $this->makeStudent('Agree One');
        $s2 = $this->makeStudent('Agree Two');
        $s3 = $this->makeStudent('Decline Three');

        // Within 2 days (< 3 days) so normal student cancellation would yield 0%.
        $origDate = now()->addDays(2)->format('Y-m-d');
        $newDate = now()->addDays(5)->format('Y-m-d');

        $b1 = $this->makeBooking($s1, ['date' => $origDate]);
        $b2 = $this->makeBooking($s2, ['date' => $origDate]);
        $b3 = $this->makeBooking($s3, ['date' => $origDate]);

        Payment::create([
            'booking_id' => $b3->id,
            'amount' => 1712.00,
            'lesson_price' => 1600.00,
            'service_fee_amount' => 112.00,
            'payment_method' => PaymentMethod::GCash,
            'status' => PaymentStatus::Completed,
            'gateway_payment_id' => 'mock_pay_b3',
            'paid_at' => now(),
        ]);

        $requests = app(BookingChangeService::class)->requestBulkReschedule(
            $b1,
            $this->instructor,
            ['date' => $newDate, 'start_time' => '09:00'],
            'Moving session to better swell.',
            [$b1->id, $b2->id, $b3->id]
        );
        $batchId = $requests->first()->batch_id;

        $this->actingAs($s1)->post(route('student.bookings.accept-reschedule', $b1->id))->assertSessionHas('success');
        $this->actingAs($s2)->post(route('student.bookings.accept-reschedule', $b2->id))->assertSessionHas('success');
        $this->actingAs($s3)->post(route('student.bookings.decline-reschedule', $b3->id), [
            'note' => 'Flying home before day 5.',
        ])->assertSessionHas('success');

        $summary = app(BookingChangeService::class)->summarizeBatch($batchId);
        $this->assertTrue($summary['has_schedule_split']);
        $this->assertSame(2, $summary['agreed_count']);
        $this->assertSame(1, $summary['declined_count']);

        // Student 3 now cancels after having clicked Decline -> MUST still get 100% lesson price (₱1,600), 7% fee retained.
        $this->actingAs($s3)
            ->post(route('student.bookings.cancel', $b3->id), [
                'reason' => 'Cannot attend without the group.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $b3->refresh();
        $this->assertSame(BookingStatus::Cancelled, $b3->status);
        $this->assertSame(1600.0, (float) $b3->refund_amount);
        $this->assertDatabaseHas('refunds', [
            'booking_id' => $b3->id,
            'amount' => 1600.00,
            'service_fee_retained' => 112.00,
            'basis' => RefundBasis::InstructorRescheduleDeclined->value,
        ]);
    }

    /** @test */
    public function expired_proposal_cannot_be_accepted_and_is_closed_by_sweep(): void
    {
        Notification::fake();

        $s1 = $this->makeStudent('Slow Surfer');
        $b1 = $this->makeBooking($s1);
        $newDate = now()->addDays(6)->format('Y-m-d');

        $cr = app(BookingChangeService::class)->requestReschedule(
            $b1,
            $this->instructor,
            ['date' => $newDate, 'start_time' => '10:00'],
            'Moving to 10 AM.'
        );

        $cr->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($s1)
            ->post(route('student.bookings.accept-reschedule', $b1->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $b1->refresh();
        $this->assertNotSame($newDate, $b1->date->format('Y-m-d'));

        // Re-test sweep command on a fresh expired proposal
        $b2 = $this->makeBooking($this->makeStudent('Sweep Surfer'), ['start_time' => '13:00', 'end_time' => '15:00', 'time_period' => 'afternoon']);
        $cr2 = app(BookingChangeService::class)->requestReschedule(
            $b2,
            $this->instructor,
            ['date' => $newDate, 'start_time' => '14:00'],
            'Afternoon swell.'
        );
        $cr2->update(['expires_at' => now()->subMinutes(5)]);

        $this->artisan('bookings:expire-stale-requests')->assertExitCode(0);

        $this->assertSame(ChangeRequestStatus::Rejected, $cr2->fresh()->status);
        Notification::assertSentTo($this->instructor, BookingChangeResolvedNotification::class);
    }

    /** @test */
    public function under_24h_batch_queues_for_admin_and_can_be_approved_in_batch(): void
    {
        $s1 = $this->makeStudent('Late One');
        $s2 = $this->makeStudent('Late Two');

        $start = now()->addHours(4);
        $origDate = $start->format('Y-m-d');
        $origStart = $start->format('H:i');
        $origEnd = $start->copy()->addHours(2)->format('H:i');
        $period = ((int) $start->format('H')) < 12 ? 'morning' : 'afternoon';

        $b1 = $this->makeBooking($s1, ['date' => $origDate, 'time_period' => $period, 'start_time' => $origStart, 'end_time' => $origEnd]);
        $b2 = $this->makeBooking($s2, ['date' => $origDate, 'time_period' => $period, 'start_time' => $origStart, 'end_time' => $origEnd]);

        $newDate = now()->addDays(4)->format('Y-m-d');

        $requests = app(BookingChangeService::class)->requestBulkReschedule(
            $b1,
            $this->instructor,
            ['date' => $newDate, 'start_time' => '09:00'],
            'Sudden squall.',
            [$b1->id, $b2->id]
        );

        $batchId = $requests->first()->batch_id;
        $this->assertNotNull($batchId);

        $this->actingAs($s1)->post(route('student.bookings.accept-reschedule', $b1->id))->assertSessionHas('success');
        $this->actingAs($s2)->post(route('student.bookings.accept-reschedule', $b2->id))->assertSessionHas('success');

        // Still on original date awaiting SISA admin approval
        $this->assertSame($origDate, $b1->fresh()->date->format('Y-m-d'));
        $this->assertSame($origDate, $b2->fresh()->date->format('Y-m-d'));

        $this->actingAs($this->admin)
            ->post(route('admin.change-requests.batch.approve', $batchId), [
                'admin_note' => 'Weather squall verified.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($newDate, $b1->fresh()->date->format('Y-m-d'));
        $this->assertSame($newDate, $b2->fresh()->date->format('Y-m-d'));
        $this->assertSame(0, BookingChangeRequest::query()->forBatch($batchId)->open()->count());
    }

    /** @test */
    public function cancelling_booking_closes_its_open_change_requests(): void
    {
        $s1 = $this->makeStudent('Cancel Surfer');
        $b1 = $this->makeBooking($s1);
        $newDate = now()->addDays(6)->format('Y-m-d');

        $cr = app(BookingChangeService::class)->requestReschedule(
            $b1,
            $this->instructor,
            ['date' => $newDate, 'start_time' => '10:00'],
            'Reschedule check.'
        );

        $this->actingAs($s1)
            ->post(route('student.bookings.cancel', $b1->id), [
                'reason' => 'Cannot make new date.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(BookingStatus::Cancelled, $b1->fresh()->status);
        $this->assertSame(ChangeRequestStatus::Rejected, $cr->fresh()->status);
    }
}
