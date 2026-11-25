<?php

namespace Tests\Unit\Services;

use App\Enums\BookingStatus;
use App\Enums\CancellationReason;
use App\Enums\FeedbackCategory;
use App\Enums\IncidentOutcome;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SessionType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\PricingService;
use App\Services\RefundCalculatorService;
use App\Services\SkillAssessmentService;
use App\Services\SystemSettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SystemSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private SystemSettingsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SystemSettingsService::class);
        $this->service->flushAndApply();
    }

    #[Test]
    public function seed_defaults_populates_all_managed_keys_idempotently(): void
    {
        $this->service->seedDefaults();

        $expectedCount = count($this->service->definitions());
        $this->assertSame($expectedCount, Setting::count());

        Setting::where('key', 'fees.service_fee_percent')->update(['value' => '9.5']);
        $this->service->seedDefaults();

        $this->assertSame($expectedCount, Setting::count());
        $this->assertSame('9.5', Setting::where('key', 'fees.service_fee_percent')->value('value'));
    }

    #[Test]
    public function updating_settings_refreshes_cache_and_updates_runtime_services(): void
    {
        $this->service->seedDefaults();

        $payload = $this->service->defaultPayload();
        $payload['services']['teaching'] = ['min_rate' => 700, 'max_rate' => 1100];
        $payload['fees']['service_fee_percent'] = 10.0;
        $payload['booking']['request_cutoff_minutes'] = 90;
        $payload['booking']['accept_window_hours'] = 4;
        $payload['booking']['waiver_hold_minutes'] = 20;
        $payload['booking']['payment_hold_minutes'] = 25;
        $payload['refunds']['short_term_full_days'] = 10;
        $payload['refunds']['short_term_half_days'] = 5;
        $payload['refunds']['short_term_half_percent'] = 40;
        $payload['skill_assessment']['thresholds']['intermediate_knowledge_required'] = 2;
        $payload['incident_severity_mapping']['minor'] = '2_strikes';
        $payload['incident_severity_mapping']['category_overrides'] = [
            FeedbackCategory::MinorLate->value => IncidentOutcome::NoStrike->value,
        ];

        $this->service->updateFromPayload($payload);

        $this->assertSame(700.0, SessionType::Teaching->minRate());
        $this->assertSame(1100.0, SessionType::Teaching->maxRate());
        $this->assertSame(10.0, PricingService::serviceFeePercent());
        $this->assertSame(90, Booking::requestCutoffMinutes());
        $this->assertSame(4, Booking::acceptWindowHours());
        $this->assertSame(20, Booking::waiverHoldMinutes());
        $this->assertSame(25, Booking::paymentHoldMinutes());
        $this->assertSame(IncidentOutcome::TwoStrikes, FeedbackCategory::MinorCommunication->suggestedOutcome());
        $this->assertSame(IncidentOutcome::NoStrike, FeedbackCategory::MinorLate->suggestedOutcome());

        // Skill assessment reads updated threshold dynamically.
        $assessment = app(SkillAssessmentService::class)->evaluate([
            'can_swim_deep_water' => true,
            'is_first_time' => false,
            'skills' => [
                'basic' => [0, 1],
                'intermediate' => [0, 1, 2, 3],
                'advanced' => [],
            ],
            'answers' => [0 => 1, 1 => 1, 2 => 1, 3 => 0],
        ]);
        $this->assertSame('intermediate', $assessment['assessed_level']);

        // Refund calculator reads updated short-term cutoffs & percentage.
        $now = Carbon::create(2026, 11, 20, 10, 0, 0, 'Asia/Manila');
        $booking = new Booking([
            'date' => $now->copy()->addDays(6)->toDateString(),
            'original_date' => $now->copy()->addDays(12)->toDateString(),
            'is_long_term' => false,
            'lesson_price' => 1000,
            'service_fee_percent' => 10,
            'service_fee_amount' => 100,
            'total_amount' => 1100,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Completed,
        ]);
        $booking->created_at = $now->copy()->subDays(6);
        $booking->setRelation('payment', new Payment([
            'payment_method' => PaymentMethod::GCash,
            'status' => PaymentStatus::Completed,
            'gateway_payment_id' => 'mock_pay_1',
            'paid_at' => $now->copy()->subDays(6),
        ]));

        $refund = app(RefundCalculatorService::class)->calculate($booking, CancellationReason::Student, $now);
        $this->assertSame(40, $refund->refundPercentage);
        $this->assertSame(400.0, $refund->refundAmount);
    }

    #[Test]
    public function reset_to_defaults_restores_baseline_config_values(): void
    {
        $payload = $this->service->defaultPayload();
        $payload['fees']['service_fee_percent'] = 12.5;
        $payload['booking']['waiver_hold_minutes'] = 30;
        $this->service->updateFromPayload($payload);

        $this->assertSame(12.5, PricingService::serviceFeePercent());
        $this->assertSame(30, Booking::waiverHoldMinutes());

        $this->service->resetToDefaults();

        $this->assertSame(7.0, PricingService::serviceFeePercent());
        $this->assertSame(15, Booking::waiverHoldMinutes());
        $this->assertSame(600.0, SessionType::Teaching->minRate());
    }
}
