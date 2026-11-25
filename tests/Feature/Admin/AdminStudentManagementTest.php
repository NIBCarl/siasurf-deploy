<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\InstructorLevel;
use App\Enums\PaymentStatus;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Enums\TimePeriod;
use App\Models\Booking;
use App\Models\SurfSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminStudentManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['student', 'instructor', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->admin = User::factory()->admin()->create();
        $this->admin->assignRole('admin');

        $this->student = User::factory()->student()->create(['name' => 'Maya Surfer']);
        $this->student->assignRole('student');
        $this->student->studentProfile()->create([
            'skill_level' => 'beginner',
            'birthday' => '2000-05-15',
            'country' => 'Philippines',
            'status' => 'active',
            'is_first_time' => true,
        ]);

        $this->instructor = User::factory()->instructor()->create();
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level2,
            'status' => 'active',
            'rate_per_hour' => 800,
        ]);
    }

    public function test_non_admins_cannot_access_student_crud_or_lifecycle_routes(): void
    {
        $this->get(route('admin.students.create'))->assertRedirect(route('login'));
        $this->post(route('admin.students.store'), [])->assertRedirect(route('login'));
        $this->get(route('admin.students.edit', $this->student))->assertRedirect(route('login'));
        $this->put(route('admin.students.update', $this->student), [])->assertRedirect(route('login'));
        $this->delete(route('admin.students.destroy', $this->student))->assertRedirect(route('login'));
        $this->post(route('admin.students.restore', $this->student))->assertRedirect(route('login'));
        $this->delete(route('admin.students.force-destroy', $this->student))->assertRedirect(route('login'));

        foreach ([$this->student, $this->instructor] as $user) {
            $this->actingAs($user)->get(route('admin.students.create'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.students.store'), [])->assertForbidden();
            $this->actingAs($user)->get(route('admin.students.edit', $this->student))->assertForbidden();
            $this->actingAs($user)->put(route('admin.students.update', $this->student), [])->assertForbidden();
            $this->actingAs($user)->delete(route('admin.students.destroy', $this->student))->assertForbidden();
            $this->actingAs($user)->post(route('admin.students.restore', $this->student))->assertForbidden();
            $this->actingAs($user)->delete(route('admin.students.force-destroy', $this->student))->assertForbidden();
        }
    }

    public function test_admin_can_create_student_and_enforces_age_five_minimum(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Students/Create'));

        // Under 5 years old rejected.
        $this->actingAs($this->admin)
            ->from(route('admin.students.create'))
            ->post(route('admin.students.store'), [
                'name' => 'Too Young',
                'email' => 'young@example.test',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'birthday' => now()->subYears(3)->toDateString(),
                'skill_level' => 'beginner',
                'status' => 'active',
            ])
            ->assertSessionHasErrors(['birthday']);

        $this->actingAs($this->admin)
            ->post(route('admin.students.store'), [
                'name' => 'Leo Wave',
                'email' => 'leo@example.test',
                'phone' => '+63 917 555 0101',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'birthday' => '1998-08-20',
                'country' => 'Philippines',
                'skill_level' => 'intermediate',
                'status' => 'active',
                'is_first_time' => false,
                'has_board' => true,
                'height' => 172,
                'weight' => 68,
                'emergency_contact_name' => 'Ana Wave',
                'emergency_contact_phone' => '+63 917 555 0102',
                'medical_info' => 'None',
            ])
            ->assertRedirect();

        $created = User::where('email', 'leo@example.test')->firstOrFail();
        $this->assertTrue($created->isStudent());
        $this->assertTrue($created->hasRole('student'));
        $this->assertNotNull($created->email_verified_at);
        $this->assertSame('intermediate', $created->studentProfile->skill_level);
        $this->assertSame('Philippines', $created->studentProfile->country);
        $this->assertTrue($created->studentProfile->has_board);
    }

    public function test_admin_can_edit_student_and_birthday_is_locked_during_active_bookings(): void
    {
        $spot = SurfSpot::factory()->create(['difficulty' => 'beginner']);
        $booking = Booking::create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $spot->id,
            'date' => now()->addDays(3)->toDateString(),
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
            'student_age' => 26,
            'student_count' => 1,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Completed,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.students.edit', $this->student))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Students/Edit')
                ->where('hasActiveBookings', true)
            );

        // Changing birthday while confirmed booking exists must fail.
        $this->actingAs($this->admin)
            ->from(route('admin.students.edit', $this->student))
            ->put(route('admin.students.update', $this->student), [
                'name' => 'Maya Updated',
                'email' => $this->student->email,
                'birthday' => '1999-01-01',
                'skill_level' => 'intermediate',
                'status' => 'active',
            ])
            ->assertSessionHasErrors(['birthday']);

        // Complete the booking, then birthday & optional password update succeed.
        $booking->update(['status' => BookingStatus::Completed]);

        $originalPassword = $this->student->password;
        $this->actingAs($this->admin)
            ->put(route('admin.students.update', $this->student), [
                'name' => 'Maya Updated',
                'email' => $this->student->email,
                'phone' => '+63 918 000 1111',
                'birthday' => '1999-01-01',
                'country' => 'Australia',
                'skill_level' => 'intermediate',
                'status' => 'active',
                'is_first_time' => false,
                'has_board' => true,
            ])
            ->assertRedirect(route('admin.students.show', $this->student));

        $this->student->refresh();
        $this->assertSame('Maya Updated', $this->student->name);
        $this->assertSame($originalPassword, $this->student->password);
        $this->assertSame('1999-01-01', $this->student->studentProfile->birthday?->format('Y-m-d'));
        $this->assertSame('intermediate', $this->student->studentProfile->skill_level);

        // Providing a new password updates the hash.
        $this->actingAs($this->admin)
            ->put(route('admin.students.update', $this->student), [
                'name' => 'Maya Updated',
                'email' => $this->student->email,
                'password' => 'NewSecret123!',
                'password_confirmation' => 'NewSecret123!',
                'birthday' => '1999-01-01',
                'skill_level' => 'intermediate',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.students.show', $this->student));

        $this->assertTrue(Hash::check('NewSecret123!', $this->student->fresh()->password));
    }

    public function test_archive_is_blocked_with_active_booking_and_preserves_completed_history(): void
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
            'student_age' => 26,
            'student_count' => 1,
            'status' => BookingStatus::Pending,
            'payment_status' => PaymentStatus::Pending,
        ]);

        // Blocked while pending booking exists.
        $this->actingAs($this->admin)
            ->from(route('admin.students.show', $this->student))
            ->delete(route('admin.students.destroy', $this->student))
            ->assertRedirect(route('admin.students.show', $this->student))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $this->student->id, 'deleted_at' => null]);

        // Once completed, archive succeeds.
        $booking->update(['status' => BookingStatus::Completed, 'payment_status' => PaymentStatus::Completed]);

        $this->actingAs($this->admin)
            ->delete(route('admin.students.destroy', $this->student))
            ->assertRedirect(route('admin.students.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('users', ['id' => $this->student->id]);

        // Excluded from default Active tab, visible in Archived tab, historical booking still resolves student name.
        $this->actingAs($this->admin)
            ->get(route('admin.students.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('students.data', 0));

        $this->actingAs($this->admin)
            ->get(route('admin.students.index', ['view' => 'archived']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('students.data', 1)
                ->where('students.data.0.id', $this->student->id)
            );

        $this->assertSame('Maya Surfer', $booking->fresh('student')->student?->name);

        // Archived student cannot log in.
        $this->post('/logout');
        $this->assertGuest();
        $this->post('/login', [
            'email' => $this->student->email,
            'password' => 'password',
        ])->assertSessionHasErrors(['email']);
        $this->assertGuest();

        // Force delete blocked while historical booking exists.
        $this->actingAs($this->admin)
            ->from(route('admin.students.show', $this->student))
            ->delete(route('admin.students.force-destroy', $this->student))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $this->student->id]);

        // Restore brings student back to active directory.
        $this->actingAs($this->admin)
            ->post(route('admin.students.restore', $this->student))
            ->assertSessionHas('success');
        $this->assertDatabaseHas('users', ['id' => $this->student->id, 'deleted_at' => null]);
    }

    public function test_admin_can_force_delete_archived_student_with_no_historical_records(): void
    {
        // Must archive first.
        $this->actingAs($this->admin)
            ->from(route('admin.students.show', $this->student))
            ->delete(route('admin.students.force-destroy', $this->student))
            ->assertSessionHas('error');

        $this->actingAs($this->admin)
            ->delete(route('admin.students.destroy', $this->student))
            ->assertRedirect(route('admin.students.index'));

        $this->actingAs($this->admin)
            ->delete(route('admin.students.force-destroy', $this->student))
            ->assertRedirect(route('admin.students.index', ['view' => 'archived']))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $this->student->id]);
        $this->assertDatabaseMissing('student_profiles', ['user_id' => $this->student->id]);
    }
}
