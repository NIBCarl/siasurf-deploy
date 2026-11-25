<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\FeedbackCategory;
use App\Enums\IncidentOutcome;
use App\Enums\InstructorLevel;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SessionType;
use App\Enums\SkillLevel;
use App\Enums\TimePeriod;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\SurfSpot;
use App\Models\User;
use App\Services\FeedbackService;
use App\Services\PricingService;
use App\Services\RefundCalculatorService;
use App\Services\SkillAssessmentService;
use App\Services\SystemSettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
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

        $this->instructor = User::factory()->instructor()->create();
        $this->instructor->assignRole('instructor');
        $this->instructor->instructorProfile()->create([
            'level' => InstructorLevel::Level3,
            'status' => 'active',
            'rate_per_hour' => 700,
            'guiding_rate' => 900,
            'coaching_rate' => 1600,
        ]);

        $this->student = User::factory()->student()->create();
        $this->student->assignRole('student');

        app(SystemSettingsService::class)->flushAndApply();
    }

    public function test_guests_students_and_instructors_cannot_access_admin_settings(): void
    {
        $this->get(route('admin.settings.index'))->assertRedirect(route('login'));
        $this->put(route('admin.settings.update'), [])->assertRedirect(route('login'));
        $this->post(route('admin.settings.reset'))->assertRedirect(route('login'));

        foreach ([$this->student, $this->instructor] as $user) {
            $this->actingAs($user)->get(route('admin.settings.index'))->assertForbidden();
            $this->actingAs($user)->put(route('admin.settings.update'), [])->assertForbidden();
            $this->actingAs($user)->post(route('admin.settings.reset'))->assertForbidden();
        }
    }

    public function test_admin_can_view_settings_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Index')
                ->has('settings.services.teaching')
                ->has('settings.fees')
                ->has('settings.booking')
                ->has('settings.refunds')
                ->has('settings.skill_assessment.thresholds')
                ->has('settings.incident_severity_mapping')
                ->has('settings.strikes')
                ->has('defaults')
                ->has('meta.outcomeOptions')
                ->has('meta.concernCategories')
            );
    }

    public function test_admin_can_update_all_sections_and_changes_apply_without_altering_past_bookings(): void
    {
        $spot = SurfSpot::factory()->create(['difficulty' => 'beginner']);

        $historicalBooking = Booking::create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'surf_spot_id' => $spot->id,
            'date' => '2026-11-24',
            'time_period' => TimePeriod::Morning,
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_hours' => 2,
            'skill_level' => SkillLevel::Beginner,
            'session_type' => SessionType::Teaching,
            'hourly_rate' => 600,
            'lesson_price' => 1200,
            'service_fee_percent' => 7.00,
            'service_fee_amount' => 84.00,
            'total_amount' => 1284.00,
            'student_age' => 25,
            'student_count' => 1,
            'status' => BookingStatus::Completed,
            'payment_status' => PaymentStatus::Completed,
        ]);

        $payload = app(SystemSettingsService::class)->defaultPayload();
        $payload['services']['teaching'] = ['min_rate' => 750, 'max_rate' => 1200];
        $payload['fees']['service_fee_percent'] = 9.0;
        $payload['fees']['cash_remittance_days'] = 10;
        $payload['booking'] = [
            'request_cutoff_minutes' => 90,
            'accept_window_hours' => 3,
            'waiver_hold_minutes' => 20,
            'payment_hold_minutes' => 25,
            'horizon_days' => 45,
            'change_approval_threshold_hours' => 12,
        ];
        $payload['refunds'] = [
            'long_term_threshold_days' => 25,
            'long_term_window_days' => 20,
            'max_days_from_payment' => 25,
            'short_term_full_days' => 10,
            'short_term_half_days' => 4,
            'short_term_half_percent' => 40,
        ];
        $payload['skill_assessment']['thresholds'] = [
            'intermediate_skills_required' => 3,
            'advanced_skills_required' => 3,
            'intermediate_knowledge_required' => 2,
            'advanced_knowledge_required' => 4,
        ];
        $payload['incident_severity_mapping'] = [
            'minor' => IncidentOutcome::NoStrike->value,
            'major' => IncidentOutcome::ThreeStrikes->value,
            'critical' => IncidentOutcome::Critical->value,
            'category_overrides' => [
                FeedbackCategory::MinorLate->value => IncidentOutcome::OneStrike->value,
            ],
        ];
        $payload['strikes'] = [
            'fine_amount' => 750,
            'tier2_suspension_days' => 10,
            'tier3_suspension_days' => 45,
        ];

        $this->actingAs($this->admin)
            ->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), $payload)
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $this->admin->id,
            Setting::where('key', 'fees.service_fee_percent')->value('updated_by')
        );

        // 1. Instructor rate validation enforces new Teaching band (750-1200).
        $rules = PricingService::rateValidationRulesForLevel(1);
        $this->assertSame('required|numeric|min:750|max:1200', $rules['rate_per_hour']);

        // 2. PricingService uses updated 9% service fee.
        $this->instructor->instructorProfile()->update(['rate_per_hour' => 800]);
        $price = app(PricingService::class)->calculatePrice(
            $this->instructor->instructorProfile->fresh(),
            1,
            [],
            null,
            2,
            SessionType::Teaching,
            SkillLevel::Beginner
        );
        $this->assertSame(9.0, $price['service_fee_percent']);
        $this->assertSame(144.0, $price['service_fee_amount']);
        $this->assertSame(1744.0, $price['total']);

        // 3. Booking deadlines & cutoff use updated booking period settings.
        $baseNow = Carbon::create(2026, 11, 25, 8, 0, 0, 'Asia/Manila');
        Carbon::setTestNow($baseNow);
        try {
            $futureBooking = new Booking([
                'date' => '2026-11-28',
                'start_time' => '10:00',
                'end_time' => '12:00',
            ]);
            $this->assertSame('2026-11-28 08:30', $futureBooking->bookingCutoffAt()?->format('Y-m-d H:i'));
            $this->assertSame('2026-11-25 08:20', $futureBooking->checkoutDeadlineFrom($baseNow)->format('Y-m-d H:i'));
            $this->assertSame('2026-11-25 08:25', $futureBooking->paymentDeadlineFrom($baseNow)->format('Y-m-d H:i'));
            $this->assertSame('2026-11-25 11:00', $futureBooking->acceptDeadlineFrom($baseNow)->format('Y-m-d H:i'));
        } finally {
            Carbon::setTestNow();
        }

        // 4. Refund calculation honors updated short-term thresholds (40% at 4-9 days).
        $cancelNow = Carbon::create(2026, 11, 20, 10, 0, 0, 'Asia/Manila');
        $paidBooking = new Booking([
            'date' => $cancelNow->copy()->addDays(6)->toDateString(),
            'original_date' => $cancelNow->copy()->addDays(10)->toDateString(),
            'is_long_term' => false,
            'lesson_price' => 1000,
            'service_fee_percent' => 9,
            'service_fee_amount' => 90,
            'total_amount' => 1090,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Completed,
        ]);
        $paidBooking->created_at = $cancelNow->copy()->subDays(4);
        $paidBooking->setRelation('payment', new Payment([
            'payment_method' => PaymentMethod::GCash,
            'status' => PaymentStatus::Completed,
            'gateway_payment_id' => 'mock_pay_refund',
            'paid_at' => $cancelNow->copy()->subDays(4),
        ]));
        $refund = app(RefundCalculatorService::class)->calculate($paidBooking, CancellationReason::Student, $cancelNow);
        $this->assertSame(40, $refund->refundPercentage);

        // 5. Skill pre-assessment honors updated thresholds.
        $evaluated = app(SkillAssessmentService::class)->evaluate([
            'can_swim_deep_water' => true,
            'is_first_time' => false,
            'skills' => ['basic' => [0, 1], 'intermediate' => [0, 1, 2], 'advanced' => []],
            'answers' => [0 => 1, 1 => 1, 2 => 1, 3 => 0],
        ]);
        $this->assertSame('intermediate', $evaluated['assessed_level']);

        // 6. Private concern submission uses updated severity mapping & category override,
        // while student-facing options never expose outcomes or severity.
        $studentOptions = FeedbackCategory::studentConcernOptions();
        foreach ($studentOptions as $option) {
            $this->assertArrayNotHasKey('severity', $option);
            $this->assertArrayNotHasKey('strikes', $option);
            $this->assertArrayNotHasKey('suggested_outcome', $option);
        }

        $result = app(FeedbackService::class)->submit($historicalBooking, $this->student, [
            'submit_review' => false,
            'submit_concern' => true,
            'concern_categories' => [FeedbackCategory::MinorLate->value],
            'concern_details' => 'Instructor arrived 25 minutes late without sending any message beforehand.',
        ]);
        $this->assertSame(IncidentOutcome::OneStrike, $result['incident']?->suggested_outcome);

        // 7. Historical booking retains its original financial snapshot.
        $historicalBooking->refresh();
        $this->assertSame('600.00', (string) $historicalBooking->hourly_rate);
        $this->assertSame('7.00', (string) $historicalBooking->service_fee_percent);
        $this->assertSame('84.00', (string) $historicalBooking->service_fee_amount);
        $this->assertSame('1284.00', (string) $historicalBooking->total_amount);
    }

    public function test_validation_rejects_invalid_cross_field_and_domain_values(): void
    {
        $base = app(SystemSettingsService::class)->defaultPayload();

        // max_rate < min_rate
        $badRates = $base;
        $badRates['services']['teaching'] = ['min_rate' => 1000, 'max_rate' => 800];
        $this->actingAs($this->admin)
            ->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), $badRates)
            ->assertSessionHasErrors(['services.teaching.max_rate']);

        // request_cutoff_minutes < payment_hold_minutes + 30 (FR 1.18 invariant)
        $badCutoff = $base;
        $badCutoff['booking']['payment_hold_minutes'] = 30;
        $badCutoff['booking']['request_cutoff_minutes'] = 55;
        $this->actingAs($this->admin)
            ->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), $badCutoff)
            ->assertSessionHasErrors(['booking.request_cutoff_minutes']);

        // short_term_full_days <= short_term_half_days
        $badRefunds = $base;
        $badRefunds['refunds']['short_term_full_days'] = 3;
        $badRefunds['refunds']['short_term_half_days'] = 5;
        $this->actingAs($this->admin)
            ->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), $badRefunds)
            ->assertSessionHasErrors(['refunds.short_term_full_days']);

        // advanced_knowledge_required < intermediate_knowledge_required
        $badKnowledge = $base;
        $badKnowledge['skill_assessment']['thresholds']['intermediate_knowledge_required'] = 4;
        $badKnowledge['skill_assessment']['thresholds']['advanced_knowledge_required'] = 2;
        $this->actingAs($this->admin)
            ->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), $badKnowledge)
            ->assertSessionHasErrors(['skill_assessment.thresholds.advanced_knowledge_required']);

        // invalid incident outcome
        $badOutcome = $base;
        $badOutcome['incident_severity_mapping']['minor'] = 'invalid_outcome';
        $this->actingAs($this->admin)
            ->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), $badOutcome)
            ->assertSessionHasErrors(['incident_severity_mapping.minor']);
    }

    public function test_admin_can_reset_settings_to_defaults(): void
    {
        $payload = app(SystemSettingsService::class)->defaultPayload();
        $payload['fees']['service_fee_percent'] = 11.0;

        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), $payload)
            ->assertRedirect();

        $this->assertSame(11.0, PricingService::serviceFeePercent());

        $this->actingAs($this->admin)
            ->from(route('admin.settings.index'))
            ->post(route('admin.settings.reset'))
            ->assertRedirect(route('admin.settings.index'));

        $this->assertSame(7.0, PricingService::serviceFeePercent());
    }
}
