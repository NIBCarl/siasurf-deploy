<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemSettingsService
{
    public const CACHE_KEY = 'siasurf.system_settings';

    public const CACHE_TTL_SECONDS = 3600;

    /**
     * Original `config('siasurf')` snapshot captured before DB overrides run.
     *
     * @var array<string, mixed>|null
     */
    private ?array $baselineConfig = null;

    /**
     * Guard to avoid redundant cache flushes inside batch DB writes.
     */
    private bool $suppressModelEvents = false;

    public function __construct()
    {
        $this->captureBaseline();
    }

    /**
     * Capture the unmodified config/siasurf.php (.env-backed) baseline once.
     *
     * @return array<string, mixed>
     */
    private function captureBaseline(): array
    {
        if ($this->baselineConfig === null) {
            $this->baselineConfig = config('siasurf', []);
        }

        return $this->baselineConfig;
    }

    /**
     * Canonical definition table for all admin-configurable system settings.
     *
     * Low-tide forecast threshold and BR-3.1–3.6 safety invariants remain fixed
     * in code and are intentionally excluded.
     *
     * @return array<string, array{key: string, group: string, type: string, default: mixed, label: string, description: string}>
     */
    public function definitions(): array
    {
        $base = $this->captureBaseline();

        $defs = [
            // Rates & Platform Service Fee (FR 1.18)
            [
                'key' => 'services.teaching.min_rate',
                'group' => 'rates',
                'type' => 'float',
                'default' => (float) data_get($base, 'services.teaching.min_rate', 600),
                'label' => 'Teaching minimum hourly rate (₱)',
                'description' => 'Lowest hourly rate allowed for Beginner / Teaching lessons.',
            ],
            [
                'key' => 'services.teaching.max_rate',
                'group' => 'rates',
                'type' => 'float',
                'default' => (float) data_get($base, 'services.teaching.max_rate', 1000),
                'label' => 'Teaching maximum hourly rate (₱)',
                'description' => 'Highest hourly rate allowed for Beginner / Teaching lessons.',
            ],
            [
                'key' => 'services.guiding.min_rate',
                'group' => 'rates',
                'type' => 'float',
                'default' => (float) data_get($base, 'services.guiding.min_rate', 800),
                'label' => 'Guiding minimum hourly rate (₱)',
                'description' => 'Lowest hourly rate allowed for Intermediate / Guiding lessons.',
            ],
            [
                'key' => 'services.guiding.max_rate',
                'group' => 'rates',
                'type' => 'float',
                'default' => (float) data_get($base, 'services.guiding.max_rate', 1500),
                'label' => 'Guiding maximum hourly rate (₱)',
                'description' => 'Highest hourly rate allowed for Intermediate / Guiding lessons.',
            ],
            [
                'key' => 'services.coaching.min_rate',
                'group' => 'rates',
                'type' => 'float',
                'default' => (float) data_get($base, 'services.coaching.min_rate', 1500),
                'label' => 'Coaching minimum hourly rate (₱)',
                'description' => 'Lowest hourly rate allowed for Advanced / Coaching lessons.',
            ],
            [
                'key' => 'services.coaching.max_rate',
                'group' => 'rates',
                'type' => 'float',
                'default' => (float) data_get($base, 'services.coaching.max_rate', 2000),
                'label' => 'Coaching maximum hourly rate (₱)',
                'description' => 'Highest hourly rate allowed for Advanced / Coaching lessons.',
            ],
            [
                'key' => 'fees.service_fee_percent',
                'group' => 'rates',
                'type' => 'float',
                'default' => (float) data_get($base, 'fees.service_fee_percent', 7.0),
                'label' => 'Platform service fee (%)',
                'description' => 'Non-refundable percentage added on top of the lesson price.',
            ],
            [
                'key' => 'fees.cash_remittance_days',
                'group' => 'rates',
                'type' => 'integer',
                'default' => (int) data_get($base, 'fees.cash_remittance_days', 7),
                'label' => 'Cash fee remittance window (days)',
                'description' => 'Days before cash service fees owed by an instructor become overdue.',
            ],

            // Booking Periods & Cutoffs (FR 1.18)
            [
                'key' => 'booking.request_cutoff_minutes',
                'group' => 'booking',
                'type' => 'integer',
                'default' => (int) data_get($base, 'booking.request_cutoff_minutes', 60),
                'label' => 'Booking request cutoff (minutes)',
                'description' => 'Minimum lead time before lesson start; must be at least 30 minutes longer than payment hold.',
            ],
            [
                'key' => 'booking.accept_window_hours',
                'group' => 'booking',
                'type' => 'integer',
                'default' => (int) data_get($base, 'booking.accept_window_hours', 2),
                'label' => 'Instructor response period (hours)',
                'description' => 'Hours an instructor has to accept or decline a pending request.',
            ],
            [
                'key' => 'booking.waiver_hold_minutes',
                'group' => 'booking',
                'type' => 'integer',
                'default' => (int) data_get($base, 'booking.waiver_hold_minutes', 15),
                'label' => 'Waiver seat reservation hold (minutes)',
                'description' => 'Minutes a slot is held while the tourist completes the digital waiver and payment step.',
            ],
            [
                'key' => 'booking.payment_hold_minutes',
                'group' => 'booking',
                'type' => 'integer',
                'default' => (int) data_get($base, 'booking.payment_hold_minutes', 15),
                'label' => 'GCash payment hold (minutes)',
                'description' => 'Minutes a student has to pay once an instructor accepts a GCash booking.',
            ],
            [
                'key' => 'booking.horizon_days',
                'group' => 'booking',
                'type' => 'integer',
                'default' => (int) data_get($base, 'booking.horizon_days', 60),
                'label' => 'Booking horizon (days)',
                'description' => 'Maximum days ahead tourists can browse and request lessons.',
            ],
            [
                'key' => 'booking.change_approval_threshold_hours',
                'group' => 'booking',
                'type' => 'integer',
                'default' => (int) data_get($base, 'booking.change_approval_threshold_hours', 24),
                'label' => 'Late change admin-approval window (hours)',
                'description' => 'Hours before lesson start inside which instructor cancellations/reschedules need SISA approval.',
            ],

            // Cancellation & Refunds (FR 1.18)
            [
                'key' => 'refunds.long_term_threshold_days',
                'group' => 'refunds',
                'type' => 'integer',
                'default' => (int) data_get($base, 'refunds.long_term_threshold_days', 30),
                'label' => 'Long-term booking threshold (days)',
                'description' => 'Sessions booked more than this many days ahead follow the long-term policy.',
            ],
            [
                'key' => 'refunds.long_term_window_days',
                'group' => 'refunds',
                'type' => 'integer',
                'default' => (int) data_get($base, 'refunds.long_term_window_days', 30),
                'label' => 'Long-term full-refund window (days from payment)',
                'description' => 'Days after payment during which a long-term booking gets a 100% lesson-price refund.',
            ],
            [
                'key' => 'refunds.max_days_from_payment',
                'group' => 'refunds',
                'type' => 'integer',
                'default' => (int) data_get($base, 'refunds.max_days_from_payment', 30),
                'label' => 'Automatic gateway refund limit (days from payment)',
                'description' => 'Refunds older than this route to SISA manual completion.',
            ],
            [
                'key' => 'refunds.short_term_full_days',
                'group' => 'refunds',
                'type' => 'integer',
                'default' => (int) data_get($base, 'refunds.short_term_full_days', 7),
                'label' => 'Short-term full-refund cutoff (days before session)',
                'description' => 'Minimum days before session for a 100% lesson-price refund.',
            ],
            [
                'key' => 'refunds.short_term_half_days',
                'group' => 'refunds',
                'type' => 'integer',
                'default' => (int) data_get($base, 'refunds.short_term_half_days', 3),
                'label' => 'Short-term partial-refund cutoff (days before session)',
                'description' => 'Minimum days before session for a partial refund; fewer days yield 0%.',
            ],
            [
                'key' => 'refunds.short_term_half_percent',
                'group' => 'refunds',
                'type' => 'integer',
                'default' => (int) data_get($base, 'refunds.short_term_half_percent', 50),
                'label' => 'Short-term partial-refund percentage (%)',
                'description' => 'Percentage of lesson price refunded inside the partial-refund window.',
            ],

            // Skill Pre-Assessment Thresholds (FR 1.18)
            [
                'key' => 'skill_assessment.thresholds.intermediate_skills_required',
                'group' => 'skill_assessment',
                'type' => 'integer',
                'default' => (int) data_get($base, 'skill_assessment.thresholds.intermediate_skills_required', 4),
                'label' => 'Intermediate checklist skills required',
                'description' => 'Intermediate skills needed (plus all basic skills) to qualify for Intermediate checklist tier.',
            ],
            [
                'key' => 'skill_assessment.thresholds.advanced_skills_required',
                'group' => 'skill_assessment',
                'type' => 'integer',
                'default' => (int) data_get($base, 'skill_assessment.thresholds.advanced_skills_required', 4),
                'label' => 'Advanced checklist skills required',
                'description' => 'Advanced skills needed (on top of intermediate) to qualify for Advanced checklist tier.',
            ],
            [
                'key' => 'skill_assessment.thresholds.intermediate_knowledge_required',
                'group' => 'skill_assessment',
                'type' => 'integer',
                'default' => (int) data_get($base, 'skill_assessment.thresholds.intermediate_knowledge_required', 3),
                'label' => 'Intermediate knowledge score required',
                'description' => 'Correct knowledge answers required (with water safety passed) for Intermediate knowledge tier.',
            ],
            [
                'key' => 'skill_assessment.thresholds.advanced_knowledge_required',
                'group' => 'skill_assessment',
                'type' => 'integer',
                'default' => (int) data_get($base, 'skill_assessment.thresholds.advanced_knowledge_required', 4),
                'label' => 'Advanced knowledge score required',
                'description' => 'Correct knowledge answers required (with water safety passed) for Advanced knowledge tier.',
            ],

            // Incident Severity Mapping & Strike Ladder (FR 1.23)
            [
                'key' => 'incident_severity_mapping.minor',
                'group' => 'incident_severity',
                'type' => 'string',
                'default' => (string) data_get($base, 'incident_severity_mapping.minor', '1_strike'),
                'label' => 'Minor concern suggested outcome',
                'description' => 'SISA-only suggested outcome when a Minor concern item is selected.',
            ],
            [
                'key' => 'incident_severity_mapping.major',
                'group' => 'incident_severity',
                'type' => 'string',
                'default' => (string) data_get($base, 'incident_severity_mapping.major', '2_strikes'),
                'label' => 'Major concern suggested outcome',
                'description' => 'SISA-only suggested outcome when a Major concern item is selected.',
            ],
            [
                'key' => 'incident_severity_mapping.critical',
                'group' => 'incident_severity',
                'type' => 'string',
                'default' => (string) data_get($base, 'incident_severity_mapping.critical', 'critical'),
                'label' => 'Critical concern suggested outcome',
                'description' => 'SISA-only suggested outcome when a Critical concern item is selected.',
            ],
            [
                'key' => 'incident_severity_mapping.category_overrides',
                'group' => 'incident_severity',
                'type' => 'json',
                'default' => data_get($base, 'incident_severity_mapping.category_overrides', []),
                'label' => 'Checklist item outcome overrides',
                'description' => 'Optional per-item suggested outcome overrides mapped by concern category key.',
            ],
            [
                'key' => 'strikes.fine_amount',
                'group' => 'incident_severity',
                'type' => 'float',
                'default' => (float) data_get($base, 'strikes.fine_amount', 500),
                'label' => 'Tier 1 strike fine amount (₱)',
                'description' => 'Fine issued for 1 active strike (collected outside the system by SISA).',
            ],
            [
                'key' => 'strikes.tier2_suspension_days',
                'group' => 'incident_severity',
                'type' => 'integer',
                'default' => (int) data_get($base, 'strikes.tier2_suspension_days', 7),
                'label' => 'Tier 2 suspension duration (days)',
                'description' => 'Automatic suspension length when an instructor reaches 2 active strikes.',
            ],
            [
                'key' => 'strikes.tier3_suspension_days',
                'group' => 'incident_severity',
                'type' => 'integer',
                'default' => (int) data_get($base, 'strikes.tier3_suspension_days', 30),
                'label' => 'Tier 3 suspension duration (days)',
                'description' => 'Automatic suspension length when an instructor reaches 3+ active strikes.',
            ],
        ];

        $indexed = [];
        foreach ($defs as $def) {
            $indexed[$def['key']] = $def;
        }

        return $indexed;
    }

    /**
     * Stored setting values decoded to their PHP types, keyed by `key`.
     *
     * @return array<string, mixed>
     */
    public function storedValues(): array
    {
        try {
            if (! Schema::hasTable('settings')) {
                return [];
            }

            $rows = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
                return Setting::query()
                    ->get(['key', 'type', 'value'])
                    ->mapWithKeys(fn (Setting $row) => [
                        $row->key => $row->castValue(),
                    ])
                    ->all();
            });

            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Overlay persisted DB settings onto Laravel's runtime `siasurf` config.
     * Only keys that actually exist in `settings` are written so tests that
     * override config without seeding `settings` remain unaffected.
     */
    public function applyToConfig(): void
    {
        $stored = $this->storedValues();

        if ($stored === []) {
            return;
        }

        $definitions = $this->definitions();

        foreach ($stored as $key => $value) {
            if (! isset($definitions[$key])) {
                continue;
            }

            config()->set("siasurf.{$key}", $value);
        }

        if (array_key_exists('services.teaching.min_rate', $stored)) {
            config()->set('siasurf.pricing.min_rate_per_hour', (float) $stored['services.teaching.min_rate']);
        }

        if (array_key_exists('services.teaching.max_rate', $stored)) {
            config()->set('siasurf.pricing.max_rate_per_hour', (float) $stored['services.teaching.max_rate']);
        }

        foreach (['teaching', 'guiding', 'coaching'] as $service) {
            if (
                array_key_exists("services.{$service}.min_rate", $stored)
                || array_key_exists("services.{$service}.max_rate", $stored)
            ) {
                $min = (float) config("siasurf.services.{$service}.min_rate");
                $max = (float) config("siasurf.services.{$service}.max_rate");
                $default = (float) config("siasurf.services.{$service}.default_rate", $min);
                $clamped = min(max($default, $min), $max);
                config()->set("siasurf.services.{$service}.default_rate", $clamped);
            }
        }

        if (array_key_exists('strikes.tier3_suspension_days', $stored)) {
            config()->set('siasurf.strikes.suspension_days', (int) $stored['strikes.tier3_suspension_days']);
        }
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Restore baseline config values for managed keys, clear cache, and re-apply DB values.
     */
    public function flushAndApply(): void
    {
        $this->flushCache();
        $base = $this->captureBaseline();

        foreach ($this->definitions() as $key => $def) {
            config()->set("siasurf.{$key}", $def['default']);
        }

        config()->set('siasurf.pricing.min_rate_per_hour', data_get($base, 'pricing.min_rate_per_hour', 600));
        config()->set('siasurf.pricing.max_rate_per_hour', data_get($base, 'pricing.max_rate_per_hour', 1000));

        foreach (['teaching', 'guiding', 'coaching'] as $service) {
            config()->set(
                "siasurf.services.{$service}.default_rate",
                data_get($base, "services.{$service}.default_rate")
            );
        }

        config()->set('siasurf.strikes.suspension_days', data_get($base, 'strikes.suspension_days', 30));

        $this->applyToConfig();
    }

    public function shouldSuppressModelEvents(): bool
    {
        return $this->suppressModelEvents;
    }

    /**
     * Current effective settings formatted in the nested UI / validation shape.
     *
     * @return array<string, mixed>
     */
    public function formPayload(): array
    {
        $payload = [];

        foreach ($this->definitions() as $key => $def) {
            $value = config("siasurf.{$key}", $def['default']);
            Arr::set($payload, $key, $this->castForDefinition($value, $def['type']));
        }

        return $payload;
    }

    /**
     * Baseline config/siasurf.php defaults formatted in the same nested shape.
     *
     * @return array<string, mixed>
     */
    public function defaultPayload(): array
    {
        $payload = [];

        foreach ($this->definitions() as $key => $def) {
            Arr::set($payload, $key, $def['default']);
        }

        return $payload;
    }

    /**
     * Persist validated settings payload, bust cache, and re-apply to config.
     *
     * @param  array<string, mixed>  $validated
     */
    public function updateFromPayload(array $validated, ?int $userId = null): void
    {
        $definitions = $this->definitions();

        $this->suppressModelEvents = true;

        try {
            DB::transaction(function () use ($definitions, $validated, $userId): void {
                foreach ($definitions as $key => $def) {
                    if (! Arr::has($validated, $key)) {
                        continue;
                    }

                    $value = Arr::get($validated, $key);

                    if ($key === 'incident_severity_mapping.category_overrides') {
                        $value = collect(is_array($value) ? $value : [])
                            ->filter(fn ($outcome, $cat) => is_string($cat) && $cat !== '' && is_string($outcome) && $outcome !== '')
                            ->all();
                    }

                    $typed = $this->castForDefinition($value, $def['type']);

                    Setting::updateOrCreate(
                        ['key' => $key],
                        [
                            'group' => $def['group'],
                            'type' => $def['type'],
                            'value' => Setting::encodeValue($typed, $def['type']),
                            'label' => $def['label'],
                            'description' => $def['description'],
                            'updated_by' => $userId,
                        ]
                    );
                }
            });
        } finally {
            $this->suppressModelEvents = false;
        }

        $this->flushAndApply();
    }

    /**
     * Seed baseline settings idempotently without overwriting existing admin edits.
     */
    public function seedDefaults(): void
    {
        $definitions = $this->definitions();

        $this->suppressModelEvents = true;

        try {
            DB::transaction(function () use ($definitions): void {
                foreach ($definitions as $key => $def) {
                    Setting::firstOrCreate(
                        ['key' => $key],
                        [
                            'group' => $def['group'],
                            'type' => $def['type'],
                            'value' => Setting::encodeValue($def['default'], $def['type']),
                            'label' => $def['label'],
                            'description' => $def['description'],
                        ]
                    );
                }
            });
        } finally {
            $this->suppressModelEvents = false;
        }

        $this->flushAndApply();
    }

    /**
     * Reset all settings (or a single group) back to config/siasurf.php defaults.
     */
    public function resetToDefaults(?int $userId = null, ?string $group = null): void
    {
        $definitions = $this->definitions();

        $this->suppressModelEvents = true;

        try {
            DB::transaction(function () use ($definitions, $userId, $group): void {
                foreach ($definitions as $key => $def) {
                    if ($group !== null && $def['group'] !== $group) {
                        continue;
                    }

                    Setting::updateOrCreate(
                        ['key' => $key],
                        [
                            'group' => $def['group'],
                            'type' => $def['type'],
                            'value' => Setting::encodeValue($def['default'], $def['type']),
                            'label' => $def['label'],
                            'description' => $def['description'],
                            'updated_by' => $userId,
                        ]
                    );
                }
            });
        } finally {
            $this->suppressModelEvents = false;
        }

        $this->flushAndApply();
    }

    /**
     * @return array{updated_at: ?string, updated_by: ?string}
     */
    public function lastUpdatedMeta(): array
    {
        try {
            if (! Schema::hasTable('settings')) {
                return ['updated_at' => null, 'updated_by' => null];
            }

            $latest = Setting::query()
                ->with('updatedBy:id,name')
                ->whereNotNull('updated_by')
                ->latest('updated_at')
                ->first();

            return [
                'updated_at' => $latest?->updated_at?->toIso8601String(),
                'updated_by' => $latest?->updatedBy?->name,
            ];
        } catch (\Throwable) {
            return ['updated_at' => null, 'updated_by' => null];
        }
    }

    private function castForDefinition(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer', 'int' => (int) $value,
            'float', 'decimal', 'double' => (float) $value,
            'boolean', 'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json', 'array' => is_array($value) ? $value : (json_decode((string) $value, true) ?? []),
            default => (string) $value,
        };
    }
}
