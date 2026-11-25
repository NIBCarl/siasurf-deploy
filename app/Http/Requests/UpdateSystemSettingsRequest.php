<?php

namespace App\Http\Requests;

use App\Enums\FeedbackCategory;
use App\Enums\IncidentOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $outcomeValues = array_map(fn (IncidentOutcome $o) => $o->value, IncidentOutcome::cases());
        $interSkillCount = max(1, count(config('siasurf.skill_assessment.skills.intermediate', [])));
        $advSkillCount = max(1, count(config('siasurf.skill_assessment.skills.advanced', [])));
        $questionCount = max(1, count(config('siasurf.skill_assessment.questions', [])));

        return [
            'services' => ['required', 'array'],
            'services.teaching.min_rate' => ['required', 'numeric', 'min:100', 'max:50000'],
            'services.teaching.max_rate' => ['required', 'numeric', 'min:100', 'max:50000'],
            'services.guiding.min_rate' => ['required', 'numeric', 'min:100', 'max:50000'],
            'services.guiding.max_rate' => ['required', 'numeric', 'min:100', 'max:50000'],
            'services.coaching.min_rate' => ['required', 'numeric', 'min:100', 'max:50000'],
            'services.coaching.max_rate' => ['required', 'numeric', 'min:100', 'max:50000'],

            'fees' => ['required', 'array'],
            'fees.service_fee_percent' => ['required', 'numeric', 'min:0', 'max:50'],
            'fees.cash_remittance_days' => ['required', 'integer', 'min:1', 'max:60'],

            'booking' => ['required', 'array'],
            'booking.request_cutoff_minutes' => ['required', 'integer', 'min:35', 'max:1440'],
            'booking.accept_window_hours' => ['required', 'integer', 'min:1', 'max:48'],
            'booking.waiver_hold_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'booking.payment_hold_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'booking.horizon_days' => ['required', 'integer', 'min:7', 'max:365'],
            'booking.change_approval_threshold_hours' => ['required', 'integer', 'min:1', 'max:168'],

            'refunds' => ['required', 'array'],
            'refunds.long_term_threshold_days' => ['required', 'integer', 'min:7', 'max:180'],
            'refunds.long_term_window_days' => ['required', 'integer', 'min:7', 'max:180'],
            'refunds.max_days_from_payment' => ['required', 'integer', 'min:7', 'max:180'],
            'refunds.short_term_full_days' => ['required', 'integer', 'min:1', 'max:60'],
            'refunds.short_term_half_days' => ['required', 'integer', 'min:1', 'max:30'],
            'refunds.short_term_half_percent' => ['required', 'integer', 'min:0', 'max:100'],

            'skill_assessment' => ['required', 'array'],
            'skill_assessment.thresholds' => ['required', 'array'],
            'skill_assessment.thresholds.intermediate_skills_required' => ['required', 'integer', 'min:1', "max:{$interSkillCount}"],
            'skill_assessment.thresholds.advanced_skills_required' => ['required', 'integer', 'min:1', "max:{$advSkillCount}"],
            'skill_assessment.thresholds.intermediate_knowledge_required' => ['required', 'integer', 'min:1', "max:{$questionCount}"],
            'skill_assessment.thresholds.advanced_knowledge_required' => ['required', 'integer', 'min:1', "max:{$questionCount}"],

            'incident_severity_mapping' => ['required', 'array'],
            'incident_severity_mapping.minor' => ['required', 'string', Rule::in($outcomeValues)],
            'incident_severity_mapping.major' => ['required', 'string', Rule::in($outcomeValues)],
            'incident_severity_mapping.critical' => ['required', 'string', Rule::in($outcomeValues)],
            'incident_severity_mapping.category_overrides' => ['nullable', 'array'],
            'incident_severity_mapping.category_overrides.*' => ['nullable', 'string', Rule::in($outcomeValues)],

            'strikes' => ['required', 'array'],
            'strikes.fine_amount' => ['required', 'numeric', 'min:0', 'max:50000'],
            'strikes.tier2_suspension_days' => ['required', 'integer', 'min:1', 'max:365'],
            'strikes.tier3_suspension_days' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (['teaching', 'guiding', 'coaching'] as $service) {
                    $min = $this->input("services.{$service}.min_rate");
                    $max = $this->input("services.{$service}.max_rate");
                    if (is_numeric($min) && is_numeric($max) && (float) $max < (float) $min) {
                        $validator->errors()->add(
                            "services.{$service}.max_rate",
                            ucfirst($service).' maximum rate must be greater than or equal to the minimum rate.'
                        );
                    }
                }

                $cutoff = $this->input('booking.request_cutoff_minutes');
                $paymentHold = $this->input('booking.payment_hold_minutes');
                if (is_numeric($cutoff) && is_numeric($paymentHold) && (int) $cutoff < ((int) $paymentHold + 30)) {
                    $validator->errors()->add(
                        'booking.request_cutoff_minutes',
                        'The request cutoff must be at least 30 minutes longer than the payment hold.'
                    );
                }

                $fullDays = $this->input('refunds.short_term_full_days');
                $halfDays = $this->input('refunds.short_term_half_days');
                if (is_numeric($fullDays) && is_numeric($halfDays) && (int) $fullDays <= (int) $halfDays) {
                    $validator->errors()->add(
                        'refunds.short_term_full_days',
                        'The full-refund cutoff must be strictly greater than the partial-refund cutoff.'
                    );
                }

                $interKnow = $this->input('skill_assessment.thresholds.intermediate_knowledge_required');
                $advKnow = $this->input('skill_assessment.thresholds.advanced_knowledge_required');
                if (is_numeric($interKnow) && is_numeric($advKnow) && (int) $advKnow < (int) $interKnow) {
                    $validator->errors()->add(
                        'skill_assessment.thresholds.advanced_knowledge_required',
                        'Advanced knowledge requirement must be greater than or equal to the intermediate requirement.'
                    );
                }

                $tier2Days = $this->input('strikes.tier2_suspension_days');
                $tier3Days = $this->input('strikes.tier3_suspension_days');
                if (is_numeric($tier2Days) && is_numeric($tier3Days) && (int) $tier3Days < (int) $tier2Days) {
                    $validator->errors()->add(
                        'strikes.tier3_suspension_days',
                        'Tier 3 suspension duration must be greater than or equal to Tier 2 suspension duration.'
                    );
                }

                $overrides = $this->input('incident_severity_mapping.category_overrides');
                if (is_array($overrides)) {
                    foreach (array_keys($overrides) as $categoryKey) {
                        $cat = FeedbackCategory::tryFrom((string) $categoryKey);
                        if ($cat === null || ! $cat->isConcern()) {
                            $validator->errors()->add(
                                "incident_severity_mapping.category_overrides.{$categoryKey}",
                                "Unknown concern category '{$categoryKey}'."
                            );
                        }
                    }
                }
            },
        ];
    }
}
