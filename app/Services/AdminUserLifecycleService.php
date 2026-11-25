<?php

namespace App\Services;

use App\Events\LandingDataUpdated;
use App\Models\InstructorStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminUserLifecycleService
{
    public function __construct(
        protected AvatarService $avatars
    ) {}

    /**
     * Soft-delete (archive) a student or instructor account.
     *
     * @return array{ok: bool, message: string}
     */
    public function archive(User $user, ?User $actor = null): array
    {
        if ($user->trashed()) {
            return [
                'ok' => false,
                'message' => "{$user->name} is already archived.",
            ];
        }

        $activeBookings = $user->activeSlotHoldingBookingsCount();
        if ($activeBookings > 0) {
            return [
                'ok' => false,
                'message' => "Cannot archive {$user->name} while they have {$activeBookings} active or upcoming booking(s). Cancel or complete those bookings first.",
            ];
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill(['remember_token' => null])->save();
            $user->delete();
        });

        if ($user->isInstructor()) {
            LandingDataUpdated::notify('instructors');
        }

        return [
            'ok' => true,
            'message' => "{$user->name} has been archived and can be restored anytime.",
        ];
    }

    /**
     * Restore a previously archived student or instructor account.
     *
     * @return array{ok: bool, message: string}
     */
    public function restore(User $user): array
    {
        if (! $user->trashed()) {
            return [
                'ok' => false,
                'message' => "{$user->name} is not archived.",
            ];
        }

        $user->restore();

        if ($user->isInstructor()) {
            LandingDataUpdated::notify('instructors');
        }

        return [
            'ok' => true,
            'message' => "{$user->name} has been restored.",
        ];
    }

    /**
     * Permanently delete an archived account only when zero historical records exist.
     *
     * @return array{ok: bool, message: string}
     */
    public function forceDelete(User $user): array
    {
        if (! $user->trashed()) {
            return [
                'ok' => false,
                'message' => "Archive {$user->name} before permanently deleting their account.",
            ];
        }

        if ($user->hasHistoricalRecords()) {
            return [
                'ok' => false,
                'message' => "Cannot permanently delete {$user->name} because they have historical bookings, financial, or safety records that must be retained for audit compliance.",
            ];
        }

        $name = $user->name;
        $wasInstructor = $user->isInstructor();

        DB::transaction(function () use ($user): void {
            $this->avatars->remove($user);

            foreach ($user->certificates as $certificate) {
                if ($certificate->file_path && Storage::disk('local')->exists($certificate->file_path)) {
                    Storage::disk('local')->delete($certificate->file_path);
                }
            }
            $user->certificates()->delete();
            $user->availabilities()->delete();
            InstructorStatusLog::where('instructor_id', $user->id)->delete();

            if ($user->instructorProfile) {
                $user->instructorProfile->surfSpots()->detach();
                if ($user->instructorProfile->qr_code_path && Storage::disk('local')->exists($user->instructorProfile->qr_code_path)) {
                    Storage::disk('local')->delete($user->instructorProfile->qr_code_path);
                }
                $user->instructorProfile->delete();
            }

            if ($user->studentProfile) {
                $user->studentProfile->delete();
            }

            $user->syncRoles([]);
            $user->notifications()->delete();
            $user->forceDelete();
        });

        if ($wasInstructor) {
            LandingDataUpdated::notify('instructors');
        }

        return [
            'ok' => true,
            'message' => "{$name} has been permanently deleted.",
        ];
    }
}
