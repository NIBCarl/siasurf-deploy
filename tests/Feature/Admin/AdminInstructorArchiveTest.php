<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\InstructorLevel;
use App\Enums\InstructorStatus;
use App\Enums\PaymentStatus;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Enums\TimePeriod;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminInstructorArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['student', 'instructor', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->admin = User::factory()->admin()->create();
        $this->admin->assignRole('admin');

        $this->instructor = User::factory()->instructor()->create(['name' => 'Bogs Coach']);
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => InstructorStatus::Active,
            'rate_per_hour' => 800,
        ]);

        $this->student = User::factory()->student()->create();
        $this->student->assignRole('student');
    }

    public function test_non_admins_cannot_archive_restore_or_force_delete_instructors(): void
    {
        $this->delete(route('admin.instructors.destroy', $this->instructor))->assertRedirect(route('login'));
        $this->post(route('admin.instructors.restore', $this->instructor))->assertRedirect(route('login'));
        $this->delete(route('admin.instructors.force-destroy', $this->instructor))->assertRedirect(route('login'));

        foreach ([$this->student, $this->instructor] as $user) {
            $this->actingAs($user)->delete(route('admin.instructors.destroy', $this->instructor))->assertForbidden();
            $this->actingAs($user)->post(route('admin.instructors.restore', $this->instructor))->assertForbidden();
            $this->actingAs($user)->delete(route('admin.instructors.force-destroy', $this->instructor))->assertForbidden();
        }
    }

    public function test_archive_instructor_blocks_active_bookings_hides_public_routes_and_restores(): void
    {
        $spot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
        $booking = Booking::create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $spot->id,
            'date' => now()->addDays(2)->toDateString(),
            'time_period' => TimePeriod::Morning,
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'skill_level' => SkillLevel::Beginner,
            'session_type' => SessionType::Teaching,
            'hourly_rate' => 800,
            'lesson_price' => 1600,
            'service_fee_percent' => 7.00,
            'service_fee_amount' => 112.00,
            'total_amount' => 1712.00,
            'student_age' => 24,
            'student_count' => 1,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Completed,
        ]);

        // Blocked while confirmed booking exists.
        $this->actingAs($this->admin)
            ->from(route('admin.instructors.show', $this->instructor))
            ->delete(route('admin.instructors.destroy', $this->instructor))
            ->assertRedirect(route('admin.instructors.show', $this->instructor))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $this->instructor->id, 'deleted_at' => null]);

        // Complete booking -> archive succeeds.
        $booking->update(['status' => BookingStatus::Completed]);

        $this->actingAs($this->admin)
            ->delete(route('admin.instructors.destroy', $this->instructor))
            ->assertRedirect(route('admin.instructors.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('users', ['id' => $this->instructor->id]);

        // Public profile and beach QR return 404 when archived.
        $this->get(route('instructors.profile', $this->instructor))->assertNotFound();
        $this->get(route('walkin.scan', $this->instructor))->assertNotFound();

        // Historical booking still resolves instructor name via withTrashed().
        $this->assertSame('Bogs Coach', $booking->fresh('instructor')->instructor?->name);

        // Admin archived tab & dossier view work.
        $this->actingAs($this->admin)
            ->get(route('admin.instructors.index', ['view' => 'archived']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('instructors.data', 1)
                ->where('instructors.data.0.id', $this->instructor->id)
            );

        $this->actingAs($this->admin)
            ->get(route('admin.instructors.show', $this->instructor))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('isArchived', true)
                ->where('canForceDelete', false)
            );

        // Force delete blocked while booking exists.
        $this->actingAs($this->admin)
            ->from(route('admin.instructors.show', $this->instructor))
            ->delete(route('admin.instructors.force-destroy', $this->instructor))
            ->assertSessionHas('error');

        // Restore restores instructor & makes QR reachable again.
        $this->actingAs($this->admin)
            ->post(route('admin.instructors.restore', $this->instructor))
            ->assertSessionHas('success');
        $this->assertDatabaseHas('users', ['id' => $this->instructor->id, 'deleted_at' => null]);
        $this->get(route('walkin.scan', $this->instructor))->assertOk();
    }

    public function test_admin_can_force_delete_archived_instructor_with_no_historical_records(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('certificates/bogs.pdf', 'pdf');

        $this->instructor->certificates()->create([
            'type' => CertificateType::SISA->value,
            'file_path' => 'certificates/bogs.pdf',
            'status' => CertificateStatus::Verified->value,
            'verified_at' => now(),
            'expires_at' => now()->addYear(),
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.instructors.destroy', $this->instructor))
            ->assertRedirect(route('admin.instructors.index'));

        $this->actingAs($this->admin)
            ->delete(route('admin.instructors.force-destroy', $this->instructor))
            ->assertRedirect(route('admin.instructors.index', ['view' => 'archived']))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $this->instructor->id]);
        $this->assertDatabaseMissing('instructor_profiles', ['user_id' => $this->instructor->id]);
        $this->assertDatabaseMissing('certificates', ['user_id' => $this->instructor->id]);
        Storage::disk('local')->assertMissing('certificates/bogs.pdf');
    }
}
