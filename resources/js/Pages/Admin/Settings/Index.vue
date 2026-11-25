<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseButton from '@/Components/Atoms/BaseButton.vue';
import Badge from '@/Components/Atoms/Badge.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import {
  AcademicCapIcon,
  ArrowPathIcon,
  BanknotesIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  ReceiptRefundIcon,
  ShieldCheckIcon,
} from '@heroicons/vue/24/outline';

type SectionKey = 'rates' | 'booking' | 'refunds' | 'skill_assessment' | 'incident_severity';

interface OutcomeOption {
  value: string;
  label: string;
  strikes: number;
  is_critical: boolean;
  is_dismissal: boolean;
}

interface ConcernCategory {
  value: string;
  label: string;
  description: string;
  group: string;
  topic: string;
  topic_label: string;
  severity: string | null;
  severity_label: string | null;
  strikes: number | null;
  suggested_outcome: string | null;
  suggested_outcome_label: string | null;
}

interface SettingsPayload {
  services: {
    teaching: { min_rate: number; max_rate: number };
    guiding: { min_rate: number; max_rate: number };
    coaching: { min_rate: number; max_rate: number };
  };
  fees: {
    service_fee_percent: number;
    cash_remittance_days: number;
  };
  booking: {
    request_cutoff_minutes: number;
    accept_window_hours: number;
    waiver_hold_minutes: number;
    payment_hold_minutes: number;
    horizon_days: number;
    change_approval_threshold_hours: number;
  };
  refunds: {
    long_term_threshold_days: number;
    long_term_window_days: number;
    max_days_from_payment: number;
    short_term_full_days: number;
    short_term_half_days: number;
    short_term_half_percent: number;
  };
  skill_assessment: {
    thresholds: {
      intermediate_skills_required: number;
      advanced_skills_required: number;
      intermediate_knowledge_required: number;
      advanced_knowledge_required: number;
    };
  };
  incident_severity_mapping: {
    minor: string;
    major: string;
    critical: string;
    category_overrides: Record<string, string>;
  };
  strikes: {
    fine_amount: number;
    tier2_suspension_days: number;
    tier3_suspension_days: number;
  };
}

interface Props {
  settings: SettingsPayload;
  defaults: SettingsPayload;
  meta: {
    lastUpdated: { updated_at: string | null; updated_by: string | null };
    skillCounts: { basic: number; intermediate: number; advanced: number; questions: number };
    outcomeOptions: OutcomeOption[];
    concernCategories: ConcernCategory[];
  };
}

const props = defineProps<Props>();

const clonePayload = (source: SettingsPayload): SettingsPayload => ({
  services: {
    teaching: { ...source.services.teaching },
    guiding: { ...source.services.guiding },
    coaching: { ...source.services.coaching },
  },
  fees: { ...source.fees },
  booking: { ...source.booking },
  refunds: { ...source.refunds },
  skill_assessment: {
    thresholds: { ...source.skill_assessment.thresholds },
  },
  incident_severity_mapping: {
    minor: source.incident_severity_mapping.minor,
    major: source.incident_severity_mapping.major,
    critical: source.incident_severity_mapping.critical,
    category_overrides: { ...(source.incident_severity_mapping.category_overrides ?? {}) },
  },
  strikes: { ...source.strikes },
});

const activeTab = ref<SectionKey>('rates');
const showOverrides = ref(false);
const resetting = ref(false);

const form = useForm<SettingsPayload>(clonePayload(props.settings));

watch(
  () => props.settings,
  (next) => {
    const fresh = clonePayload(next);
    form.defaults(fresh);
    form.reset();
  },
  { deep: true },
);

const tabs: Array<{ key: SectionKey; label: string; icon: any; prefixes: string[] }> = [
  { key: 'rates', label: 'Rates & Fee', icon: BanknotesIcon, prefixes: ['services.', 'fees.'] },
  { key: 'booking', label: 'Booking Periods', icon: ClockIcon, prefixes: ['booking.'] },
  { key: 'refunds', label: 'Cancellation & Refunds', icon: ReceiptRefundIcon, prefixes: ['refunds.'] },
  { key: 'skill_assessment', label: 'Pre-Assessment', icon: AcademicCapIcon, prefixes: ['skill_assessment.'] },
  { key: 'incident_severity', label: 'Severity & Strikes', icon: ExclamationTriangleIcon, prefixes: ['incident_severity_mapping.', 'strikes.'] },
];

const formErrors = computed(() => form.errors as Record<string, string>);

const tabHasError = (prefixes: string[]) =>
  Object.keys(formErrors.value).some((key) => prefixes.some((prefix) => key.startsWith(prefix)));

const feeExample = computed(() => {
  const rate = Number(form.services.teaching.min_rate || 0);
  const lessonPrice = rate * 2;
  const feePct = Math.max(0, Number(form.fees.service_fee_percent || 0));
  const fee = Math.round(lessonPrice * (feePct / 100) * 100) / 100;
  return {
    rate,
    lessonPrice,
    feePct,
    fee,
    total: Math.round((lessonPrice + fee) * 100) / 100,
  };
});

const formatMinutesLabel = (minutes: number) => {
  const m = Math.max(0, Math.round(Number(minutes || 0)));
  if (m >= 60 && m % 60 === 0) {
    const h = m / 60;
    return `${h} ${h === 1 ? 'hour' : 'hours'}`;
  }
  if (m > 60) {
    const h = Math.floor(m / 60);
    const rem = m % 60;
    return `${h} ${h === 1 ? 'hr' : 'hrs'} ${rem} min`;
  }
  return `${m} min`;
};

const minRequiredCutoff = computed(() => Number(form.booking.payment_hold_minutes || 0) + 30);

const groupedConcerns = computed(() => {
  const groups: Record<string, { label: string; items: ConcernCategory[] }> = {};
  for (const item of props.meta.concernCategories) {
    if (!groups[item.topic]) {
      groups[item.topic] = { label: item.topic_label, items: [] };
    }
    groups[item.topic].items.push(item);
  }
  return Object.entries(groups).map(([topic, group]) => ({ topic, ...group }));
});

const overrideCount = computed(() =>
  Object.values(form.incident_severity_mapping.category_overrides ?? {}).filter(Boolean).length,
);

const getOverride = (categoryKey: string) =>
  form.incident_severity_mapping.category_overrides?.[categoryKey] ?? '';

const setOverride = (categoryKey: string, value: string) => {
  const next = { ...(form.incident_severity_mapping.category_overrides ?? {}) };
  if (!value) {
    delete next[categoryKey];
  } else {
    next[categoryKey] = value;
  }
  form.incident_severity_mapping.category_overrides = next;
};

const defaultTierOutcomeForGroup = (group: string) => {
  if (group === 'minor') return form.incident_severity_mapping.minor;
  if (group === 'major') return form.incident_severity_mapping.major;
  if (group === 'critical') return form.incident_severity_mapping.critical;
  return '';
};

const outcomeLabel = (value: string) =>
  props.meta.outcomeOptions.find((o) => o.value === value)?.label ?? 'Unclassified (SISA decides)';

const lastUpdatedLabel = computed(() => {
  if (!props.meta.lastUpdated.updated_at) return null;
  const formatted = new Date(props.meta.lastUpdated.updated_at).toLocaleString('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
  });
  return props.meta.lastUpdated.updated_by
    ? `${formatted} by ${props.meta.lastUpdated.updated_by}`
    : formatted;
});

const saveSettings = () => {
  form.put(route('admin.settings.update'), {
    preserveScroll: true,
  });
};

const resetAllDefaults = () => {
  if (!confirm('Restore all system settings to the baseline config/siasurf.php defaults?')) return;
  resetting.value = true;
  router.post(
    route('admin.settings.reset'),
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        resetting.value = false;
      },
    },
  );
};

const resetSectionDefaults = (group: SectionKey, label: string) => {
  if (!confirm(`Restore "${label}" settings to their default values?`)) return;
  resetting.value = true;
  router.post(
    route('admin.settings.reset'),
    { group },
    {
      preserveScroll: true,
      onFinish: () => {
        resetting.value = false;
      },
    },
  );
};
</script>

<template>
  <AppLayout>
    <Head title="Admin: System Settings" />

    <div class="space-y-8 pb-12">
      <!-- Header -->
      <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <nav class="mb-2 flex" aria-label="Breadcrumb">
            <ol class="inline-flex items-center text-sm font-medium">
              <li class="text-slate-400">Governance</li>
              <li class="mx-2 text-slate-300">/</li>
              <li class="text-slate-900">System settings</li>
            </ol>
          </nav>
          <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">System Settings</h1>
            <Badge v-if="form.isDirty" variant="warning" size="sm">Unsaved changes</Badge>
            <Badge v-else variant="success" size="sm">Live config synced</Badge>
          </div>
          <p class="mt-1 max-w-3xl text-sm font-medium text-slate-500">
            Configure SISA rate bands, service fee, booking cutoffs &amp; holds, refund windows, pre-assessment thresholds, and incident severity mapping without code changes.
          </p>
          <p v-if="lastUpdatedLabel" class="mt-1.5 text-xs text-slate-400">
            Last saved {{ lastUpdatedLabel }}
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
          <BaseButton
            type="button"
            variant="secondary"
            size="sm"
            :disabled="form.processing || resetting"
            @click="resetAllDefaults"
          >
            <ArrowPathIcon class="mr-1.5 h-4 w-4" />
            Reset to Defaults
          </BaseButton>
          <BaseButton
            type="button"
            variant="primary"
            :loading="form.processing"
            :disabled="resetting"
            @click="saveSettings"
          >
            Save Settings
          </BaseButton>
        </div>
      </div>

      <!-- Tabs -->
      <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-3">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          @click="activeTab = tab.key"
          :class="[
            'inline-flex items-center gap-2 rounded-2xl border px-4 py-2 text-xs font-bold transition-all',
            activeTab === tab.key
              ? 'border-slate-900 bg-slate-900 text-white shadow-sm'
              : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-900',
          ]"
        >
          <component :is="tab.icon" class="h-4 w-4" />
          <span>{{ tab.label }}</span>
          <span
            v-if="tabHasError(tab.prefixes)"
            class="h-2 w-2 rounded-full bg-rose-500"
            title="Contains validation errors"
          />
        </button>
      </div>

      <form class="space-y-6" @submit.prevent="saveSettings">
        <!-- Tab 1: Rates & Fee -->
        <section v-show="activeTab === 'rates'" class="space-y-6">
          <div class="rounded-3xl border border-slate-100 bg-white p-6 shadow-sm">
            <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 class="text-lg font-extrabold text-slate-900">Lesson Type Hourly Rate Ranges</h2>
                <p class="mt-0.5 text-sm text-slate-500">
                  Enforced server-side when instructors set rates and when bookings calculate totals.
                </p>
              </div>
              <button
                type="button"
                class="text-xs font-bold text-slate-500 underline hover:text-slate-800"
                @click="resetSectionDefaults('rates', 'Rates & Fee')"
              >
                Reset section
              </button>
            </div>

            <div class="grid gap-5 md:grid-cols-3">
              <!-- Teaching -->
              <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 space-y-3">
                <div class="flex items-center justify-between">
                  <span class="text-sm font-extrabold text-slate-900">Teaching</span>
                  <Badge variant="success" size="sm">Beginner · L1–L3</Badge>
                </div>
                <div>
                  <InputLabel value="Minimum rate (₱ / hr)" />
                  <TextInput
                    v-model.number="form.services.teaching.min_rate"
                    type="number"
                    min="100"
                    step="50"
                    class="mt-1 w-full"
                  />
                  <InputError :message="formErrors['services.teaching.min_rate']" class="mt-1" />
                </div>
                <div>
                  <InputLabel value="Maximum rate (₱ / hr)" />
                  <TextInput
                    v-model.number="form.services.teaching.max_rate"
                    type="number"
                    min="100"
                    step="50"
                    class="mt-1 w-full"
                  />
                  <InputError :message="formErrors['services.teaching.max_rate']" class="mt-1" />
                </div>
                <p class="text-xs text-slate-500">
                  Published band: <strong>₱{{ Number(form.services.teaching.min_rate).toLocaleString() }}–₱{{ Number(form.services.teaching.max_rate).toLocaleString() }}</strong>/hr
                </p>
              </div>

              <!-- Guiding -->
              <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 space-y-3">
                <div class="flex items-center justify-between">
                  <span class="text-sm font-extrabold text-slate-900">Guiding</span>
                  <Badge variant="warning" size="sm">Intermediate · L2–L3</Badge>
                </div>
                <div>
                  <InputLabel value="Minimum rate (₱ / hr)" />
                  <TextInput
                    v-model.number="form.services.guiding.min_rate"
                    type="number"
                    min="100"
                    step="50"
                    class="mt-1 w-full"
                  />
                  <InputError :message="formErrors['services.guiding.min_rate']" class="mt-1" />
                </div>
                <div>
                  <InputLabel value="Maximum rate (₱ / hr)" />
                  <TextInput
                    v-model.number="form.services.guiding.max_rate"
                    type="number"
                    min="100"
                    step="50"
                    class="mt-1 w-full"
                  />
                  <InputError :message="formErrors['services.guiding.max_rate']" class="mt-1" />
                </div>
                <p class="text-xs text-slate-500">
                  Published band: <strong>₱{{ Number(form.services.guiding.min_rate).toLocaleString() }}–₱{{ Number(form.services.guiding.max_rate).toLocaleString() }}</strong>/hr
                </p>
              </div>

              <!-- Coaching -->
              <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 space-y-3">
                <div class="flex items-center justify-between">
                  <span class="text-sm font-extrabold text-slate-900">Coaching</span>
                  <Badge variant="ocean" size="sm">Advanced · L3</Badge>
                </div>
                <div>
                  <InputLabel value="Minimum rate (₱ / hr)" />
                  <TextInput
                    v-model.number="form.services.coaching.min_rate"
                    type="number"
                    min="100"
                    step="50"
                    class="mt-1 w-full"
                  />
                  <InputError :message="formErrors['services.coaching.min_rate']" class="mt-1" />
                </div>
                <div>
                  <InputLabel value="Maximum rate (₱ / hr)" />
                  <TextInput
                    v-model.number="form.services.coaching.max_rate"
                    type="number"
                    min="100"
                    step="50"
                    class="mt-1 w-full"
                  />
                  <InputError :message="formErrors['services.coaching.max_rate']" class="mt-1" />
                </div>
                <p class="text-xs text-slate-500">
                  Published band: <strong>₱{{ Number(form.services.coaching.min_rate).toLocaleString() }}–₱{{ Number(form.services.coaching.max_rate).toLocaleString() }}</strong>/hr
                </p>
              </div>
            </div>
          </div>

          <div class="rounded-3xl border border-slate-100 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">Platform Service Fee &amp; Cash Remittance</h2>
            <p class="mt-0.5 text-sm text-slate-500">
              Added on top of the lesson price for tourists; never deducted from the instructor's earnings.
            </p>

            <div class="mt-5 grid gap-5 md:grid-cols-2">
              <div>
                <InputLabel value="Platform service fee (%)" />
                <TextInput
                  v-model.number="form.fees.service_fee_percent"
                  type="number"
                  min="0"
                  max="50"
                  step="0.5"
                  class="mt-1 w-full"
                />
                <p class="mt-1 text-xs text-slate-500">
                  Example 2-hr Teaching at ₱{{ feeExample.rate.toLocaleString() }}/hr: Lesson ₱{{ feeExample.lessonPrice.toLocaleString() }} + {{ feeExample.feePct }}% fee ₱{{ feeExample.fee.toLocaleString() }} = <strong>₱{{ feeExample.total.toLocaleString() }}</strong>
                </p>
                <InputError :message="formErrors['fees.service_fee_percent']" class="mt-1" />
              </div>

              <div>
                <InputLabel value="Cash fee remittance window (days)" />
                <TextInput
                  v-model.number="form.fees.cash_remittance_days"
                  type="number"
                  min="1"
                  max="60"
                  step="1"
                  class="mt-1 w-full"
                />
                <p class="mt-1 text-xs text-slate-500">
                  Days an instructor has to remit SISA's service fee share from cash lessons before it flags overdue.
                </p>
                <InputError :message="formErrors['fees.cash_remittance_days']" class="mt-1" />
              </div>
            </div>
          </div>
        </section>

        <!-- Tab 2: Booking Periods -->
        <section v-show="activeTab === 'booking'" class="rounded-3xl border border-slate-100 bg-white p-6 shadow-sm">
          <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 class="text-lg font-extrabold text-slate-900">Booking Cutoffs, Holds &amp; Response Windows</h2>
              <p class="mt-0.5 text-sm text-slate-500">
                Per FR 1.18, the booking request cutoff must be at least 30 minutes longer than the GCash payment hold.
              </p>
            </div>
            <button
              type="button"
              class="text-xs font-bold text-slate-500 underline hover:text-slate-800"
              @click="resetSectionDefaults('booking', 'Booking Periods')"
            >
              Reset section
            </button>
          </div>

          <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
            <div>
              <InputLabel value="Booking request cutoff (minutes before start)" />
              <TextInput
                v-model.number="form.booking.request_cutoff_minutes"
                type="number"
                min="35"
                max="1440"
                step="5"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Equals <strong>{{ formatMinutesLabel(form.booking.request_cutoff_minutes) }}</strong> before lesson start (minimum {{ minRequiredCutoff }} min).
              </p>
              <InputError :message="formErrors['booking.request_cutoff_minutes']" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Instructor response period (hours)" />
              <TextInput
                v-model.number="form.booking.accept_window_hours"
                type="number"
                min="1"
                max="48"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Time an instructor has to accept or decline a pending request (clamped at cutoff).
              </p>
              <InputError :message="formErrors['booking.accept_window_hours']" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Waiver seat reservation window (minutes)" />
              <TextInput
                v-model.number="form.booking.waiver_hold_minutes"
                type="number"
                min="5"
                max="120"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Temporary seat hold from reaching the waiver page until waiver &amp; payment method are submitted.
              </p>
              <InputError :message="formErrors['booking.waiver_hold_minutes']" class="mt-1" />
            </div>

            <div>
              <InputLabel value="GCash payment hold (minutes)" />
              <TextInput
                v-model.number="form.booking.payment_hold_minutes"
                type="number"
                min="5"
                max="120"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Time to complete GCash payment after the instructor accepts the booking.
              </p>
              <InputError :message="formErrors['booking.payment_hold_minutes']" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Booking horizon (days ahead)" />
              <TextInput
                v-model.number="form.booking.horizon_days"
                type="number"
                min="7"
                max="365"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Maximum advance window shown in instructor availability calendars.
              </p>
              <InputError :message="formErrors['booking.horizon_days']" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Late change approval threshold (hours)" />
              <TextInput
                v-model.number="form.booking.change_approval_threshold_hours"
                type="number"
                min="1"
                max="168"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Instructor cancellations/reschedules inside this window require SISA admin approval.
              </p>
              <InputError :message="formErrors['booking.change_approval_threshold_hours']" class="mt-1" />
            </div>
          </div>
        </section>

        <!-- Tab 3: Cancellation & Refunds -->
        <section v-show="activeTab === 'refunds'" class="rounded-3xl border border-slate-100 bg-white p-6 shadow-sm">
          <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 class="text-lg font-extrabold text-slate-900">Cancellation &amp; Tiered Refund Rules</h2>
              <p class="mt-0.5 text-sm text-slate-500">
                Applies to paid GCash bookings. Platform service fees remain non-refundable; cash bookings collect nothing online.
              </p>
            </div>
            <button
              type="button"
              class="text-xs font-bold text-slate-500 underline hover:text-slate-800"
              @click="resetSectionDefaults('refunds', 'Cancellation & Refunds')"
            >
              Reset section
            </button>
          </div>

          <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
            <div>
              <InputLabel value="Long-term booking lead threshold (days)" />
              <TextInput
                v-model.number="form.refunds.long_term_threshold_days"
                type="number"
                min="7"
                max="180"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Sessions scheduled more than this many days ahead qualify as long-term.
              </p>
              <InputError :message="formErrors['refunds.long_term_threshold_days']" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Long-term free cancellation window (days)" />
              <TextInput
                v-model.number="form.refunds.long_term_window_days"
                type="number"
                min="7"
                max="180"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Days after payment when a long-term booking gets a 100% lesson-price refund.
              </p>
              <InputError :message="formErrors['refunds.long_term_window_days']" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Auto-refund limit from payment (days)" />
              <TextInput
                v-model.number="form.refunds.max_days_from_payment"
                type="number"
                min="7"
                max="180"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Refunds past this window route to SISA manual completion.
              </p>
              <InputError :message="formErrors['refunds.max_days_from_payment']" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Short-term 100% refund cutoff (days before)" />
              <TextInput
                v-model.number="form.refunds.short_term_full_days"
                type="number"
                min="1"
                max="60"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Must be greater than the partial-refund cutoff.
              </p>
              <InputError :message="formErrors['refunds.short_term_full_days']" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Short-term partial refund cutoff (days before)" />
              <TextInput
                v-model.number="form.refunds.short_term_half_days"
                type="number"
                min="1"
                max="30"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Cancellations closer than this to the session get 0% refund.
              </p>
              <InputError :message="formErrors['refunds.short_term_half_days']" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Short-term partial refund rate (%)" />
              <TextInput
                v-model.number="form.refunds.short_term_half_percent"
                type="number"
                min="0"
                max="100"
                step="5"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Refunded between {{ form.refunds.short_term_half_days }} and {{ Math.max(0, form.refunds.short_term_full_days - 1) }} days before session.
              </p>
              <InputError :message="formErrors['refunds.short_term_half_percent']" class="mt-1" />
            </div>
          </div>
        </section>

        <!-- Tab 4: Pre-Assessment -->
        <section v-show="activeTab === 'skill_assessment'" class="rounded-3xl border border-slate-100 bg-white p-6 shadow-sm">
          <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 class="text-lg font-extrabold text-slate-900">Skill Pre-Assessment Thresholds</h2>
              <p class="mt-0.5 text-sm text-slate-500">
                Final assessed level is the lower of checklist tier and knowledge tier. First-time surfers, unconfirmed deep-water swimmers, and missed water-safety answers always cap at Beginner.
              </p>
            </div>
            <button
              type="button"
              class="text-xs font-bold text-slate-500 underline hover:text-slate-800"
              @click="resetSectionDefaults('skill_assessment', 'Pre-Assessment')"
            >
              Reset section
            </button>
          </div>

          <div class="grid gap-5 md:grid-cols-2">
            <div>
              <InputLabel :value="`Intermediate checklist skills required (out of ${meta.skillCounts.intermediate})`" />
              <TextInput
                v-model.number="form.skill_assessment.thresholds.intermediate_skills_required"
                type="number"
                min="1"
                :max="meta.skillCounts.intermediate"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Requires all {{ meta.skillCounts.basic }} basic skills plus at least this many intermediate skills.
              </p>
              <InputError :message="formErrors['skill_assessment.thresholds.intermediate_skills_required']" class="mt-1" />
            </div>

            <div>
              <InputLabel :value="`Advanced checklist skills required (out of ${meta.skillCounts.advanced})`" />
              <TextInput
                v-model.number="form.skill_assessment.thresholds.advanced_skills_required"
                type="number"
                min="1"
                :max="meta.skillCounts.advanced"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Requires qualifying for Intermediate checklist plus at least this many advanced skills.
              </p>
              <InputError :message="formErrors['skill_assessment.thresholds.advanced_skills_required']" class="mt-1" />
            </div>

            <div>
              <InputLabel :value="`Intermediate knowledge answers required (out of ${meta.skillCounts.questions})`" />
              <TextInput
                v-model.number="form.skill_assessment.thresholds.intermediate_knowledge_required"
                type="number"
                min="1"
                :max="meta.skillCounts.questions"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Water safety question must also be answered correctly.
              </p>
              <InputError :message="formErrors['skill_assessment.thresholds.intermediate_knowledge_required']" class="mt-1" />
            </div>

            <div>
              <InputLabel :value="`Advanced knowledge answers required (out of ${meta.skillCounts.questions})`" />
              <TextInput
                v-model.number="form.skill_assessment.thresholds.advanced_knowledge_required"
                type="number"
                min="1"
                :max="meta.skillCounts.questions"
                step="1"
                class="mt-1 w-full"
              />
              <p class="mt-1 text-xs text-slate-500">
                Must be greater than or equal to the intermediate knowledge score.
              </p>
              <InputError :message="formErrors['skill_assessment.thresholds.advanced_knowledge_required']" class="mt-1" />
            </div>
          </div>
        </section>

        <!-- Tab 5: Severity & Strikes -->
        <section v-show="activeTab === 'incident_severity'" class="space-y-6">
          <div class="rounded-3xl border border-slate-100 bg-white p-6 shadow-sm">
            <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 class="text-lg font-extrabold text-slate-900">Incident Severity-to-Outcome Mapping (SISA-Only)</h2>
                <p class="mt-0.5 text-sm text-slate-500">
                  Controls the internal suggested outcome shown only to SISA admins when reviewing private concerns. Tourists and instructors never see severity tiers or suggestions.
                </p>
              </div>
              <button
                type="button"
                class="text-xs font-bold text-slate-500 underline hover:text-slate-800"
                @click="resetSectionDefaults('incident_severity', 'Severity & Strikes')"
              >
                Reset section
              </button>
            </div>

            <div class="grid gap-5 md:grid-cols-3">
              <div>
                <InputLabel value="Minor concerns default outcome" />
                <select
                  v-model="form.incident_severity_mapping.minor"
                  class="mt-1 w-full rounded-lg border-sand-300 bg-white/80 text-sm shadow-sm focus:border-ocean-500 focus:ring-ocean-500"
                >
                  <option v-for="option in meta.outcomeOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
                <InputError :message="formErrors['incident_severity_mapping.minor']" class="mt-1" />
              </div>

              <div>
                <InputLabel value="Major concerns default outcome" />
                <select
                  v-model="form.incident_severity_mapping.major"
                  class="mt-1 w-full rounded-lg border-sand-300 bg-white/80 text-sm shadow-sm focus:border-ocean-500 focus:ring-ocean-500"
                >
                  <option v-for="option in meta.outcomeOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
                <InputError :message="formErrors['incident_severity_mapping.major']" class="mt-1" />
              </div>

              <div>
                <InputLabel value="Critical concerns default outcome" />
                <select
                  v-model="form.incident_severity_mapping.critical"
                  class="mt-1 w-full rounded-lg border-sand-300 bg-white/80 text-sm shadow-sm focus:border-ocean-500 focus:ring-ocean-500"
                >
                  <option v-for="option in meta.outcomeOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
                <InputError :message="formErrors['incident_severity_mapping.critical']" class="mt-1" />
              </div>
            </div>

            <!-- Collapsible per-item overrides -->
            <div class="mt-6 border-t border-slate-100 pt-5">
              <button
                type="button"
                class="inline-flex items-center gap-2 text-sm font-bold text-slate-800 hover:text-teal-700"
                @click="showOverrides = !showOverrides"
              >
                <component :is="showOverrides ? ChevronUpIcon : ChevronDownIcon" class="h-4 w-4" />
                <span>Per-checklist item overrides</span>
                <Badge v-if="overrideCount > 0" variant="info" size="sm">{{ overrideCount }} active</Badge>
              </button>
              <p class="mt-1 text-xs text-slate-500">
                Leave an item on "Use tier default" to follow the Minor / Major / Critical mapping above.
              </p>

              <div v-if="showOverrides" class="mt-4 space-y-5">
                <div
                  v-for="group in groupedConcerns"
                  :key="group.topic"
                  class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4"
                >
                  <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">
                    {{ group.label }}
                  </h3>
                  <div class="mt-3 divide-y divide-slate-200/80">
                    <div
                      v-for="item in group.items"
                      :key="item.value"
                      class="flex flex-col gap-2 py-2.5 sm:flex-row sm:items-center sm:justify-between"
                    >
                      <div class="min-w-0 pr-4">
                        <p class="text-sm font-semibold text-slate-900">{{ item.label }}</p>
                        <p class="text-xs text-slate-500">
                          Tier: {{ item.group }} · Tier default: {{ outcomeLabel(defaultTierOutcomeForGroup(item.group)) }}
                        </p>
                      </div>
                      <select
                        :value="getOverride(item.value)"
                        class="w-full shrink-0 rounded-lg border-sand-300 bg-white text-xs font-medium shadow-sm focus:border-ocean-500 focus:ring-ocean-500 sm:w-64"
                        @change="setOverride(item.value, ($event.target as HTMLSelectElement).value)"
                      >
                        <option value="">
                          Use tier default ({{ outcomeLabel(defaultTierOutcomeForGroup(item.group)) }})
                        </option>
                        <option v-for="option in meta.outcomeOptions" :key="option.value" :value="option.value">
                          {{ option.label }}
                        </option>
                      </select>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="rounded-3xl border border-slate-100 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-extrabold text-slate-900">V3 3-Strike Ladder Penalties</h2>
            <p class="mt-0.5 text-sm text-slate-500">
              Applied automatically when SISA confirms a strike on an incident report.
            </p>

            <div class="mt-5 grid gap-5 md:grid-cols-3">
              <div>
                <InputLabel value="1 active strike fine amount (₱)" />
                <TextInput
                  v-model.number="form.strikes.fine_amount"
                  type="number"
                  min="0"
                  max="50000"
                  step="50"
                  class="mt-1 w-full"
                />
                <p class="mt-1 text-xs text-slate-500">
                  Recorded as Due and settled outside the system by SISA.
                </p>
                <InputError :message="formErrors['strikes.fine_amount']" class="mt-1" />
              </div>

              <div>
                <InputLabel value="2 active strikes suspension (days)" />
                <TextInput
                  v-model.number="form.strikes.tier2_suspension_days"
                  type="number"
                  min="1"
                  max="365"
                  step="1"
                  class="mt-1 w-full"
                />
                <p class="mt-1 text-xs text-slate-500">
                  Requires SISA reinstatement approval after elapsed period.
                </p>
                <InputError :message="formErrors['strikes.tier2_suspension_days']" class="mt-1" />
              </div>

              <div>
                <InputLabel value="3+ active strikes suspension (days)" />
                <TextInput
                  v-model.number="form.strikes.tier3_suspension_days"
                  type="number"
                  min="1"
                  max="365"
                  step="1"
                  class="mt-1 w-full"
                />
                <p class="mt-1 text-xs text-slate-500">
                  Triggers SISA ID confiscation notice outside the system.
                </p>
                <InputError :message="formErrors['strikes.tier3_suspension_days']" class="mt-1" />
              </div>
            </div>
          </div>
        </section>

        <!-- Immutability & Fixed Safety Notice -->
        <aside class="rounded-3xl border border-teal-100 bg-teal-50/70 p-5">
          <div class="flex items-start gap-3">
            <ShieldCheckIcon class="mt-0.5 h-5 w-5 shrink-0 text-teal-700" />
            <div class="space-y-1 text-xs leading-relaxed text-teal-900">
              <p class="font-extrabold uppercase tracking-wider text-teal-800">Safety &amp; Historical Record Guarantees</p>
              <p>
                Existing bookings store their assessed level, instructor level, zone level, lesson type, hourly rate, service fee percentage, and totals at the time of booking — updating settings here never alters past booking records.
              </p>
              <p>
                Hard-coded safety invariants (BR-3.1–3.6 student limits, age rules, spot difficulty caps) and the Stormglass low-tide warning threshold remain fixed in code and cannot be overridden.
              </p>
            </div>
          </div>
        </aside>
      </form>
    </div>
  </AppLayout>
</template>
