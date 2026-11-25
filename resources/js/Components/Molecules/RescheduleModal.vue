<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import axios from 'axios'
import Modal from '@/Components/Modal.vue'
import AppButton from '@/Components/UI/AppButton.vue'
import AppPanel from '@/Components/UI/AppPanel.vue'
import AvailabilityCalendar from '@/Components/UI/AvailabilityCalendar.vue'
import { formatPhilippineDate } from '@/utils/philippineTime'

export interface SessionBookingOption {
  id: number
  booking_ref?: string
  student_name: string
  status: string
  is_reserved?: boolean
  student_count?: number
  duration_hours?: number
  start_time?: string | null
  end_time?: string | null
  can_be_changed?: boolean
  has_pending_change_request?: boolean
  requires_admin_approval_for_change?: boolean
  latest_reschedule_state?: string | null
  show_url?: string
}

export interface RescheduleBookingTarget {
  id: number
  instructor_id: number
  surf_spot_id?: number | null
  date: string
  time_period: string
  start_time?: string | null
  end_time?: string | null
  duration_hours?: number | null
  student_count?: number | null
  skill_level?: string | null
  session_type?: string | null
  requires_admin_approval_for_change?: boolean
  student: {
    name: string
  }
}

interface StartSlotOption {
  start_time: string
  end_time: string
  time_period: string
  start_label: string
  end_label: string
  is_current: boolean
}

interface RescheduleAvailabilityResponse {
  date: string
  day_label: string
  duration_hours: number
  max_duration_hours?: number
  group_size?: number
  total_students?: number
  periods: Record<string, {
    available: boolean
    reason: string | null
    slots: StartSlotOption[]
  }>
  has_any_slot: boolean
}

const props = defineProps<{
  show: boolean
  booking: RescheduleBookingTarget | null
  requiresAdminApproval?: boolean
  sessionBookings?: SessionBookingOption[]
}>()

const emit = defineEmits<{
  close: []
}>()

const form = useForm({
  type: 'reschedule',
  date: '',
  start_time: '',
  reason: '',
  booking_ids: [] as number[],
})

const scopeMode = ref<'session' | 'single'>('session')
const selectedIds = ref<number[]>([])
const availability = ref<RescheduleAvailabilityResponse | null>(null)
const loadingSlots = ref(false)
const slotError = ref<string | null>(null)

const isSiblingSelectable = (member: SessionBookingOption): boolean =>
  member.can_be_changed !== false
  && !member.is_reserved
  && !member.has_pending_change_request

const siblingDisabledReason = (member: SessionBookingOption): string | null => {
  if (member.is_reserved) return 'In 15-min waiver/checkout hold'
  if (member.has_pending_change_request) return 'Already has an open change request'
  if (member.can_be_changed === false) return `Status (${member.status.replace(/_/g, ' ')}) cannot be changed`
  return null
}

const normalizedSessionBookings = computed<SessionBookingOption[]>(() => {
  if (!props.booking) return []
  const list = props.sessionBookings ?? []
  if (list.length > 0) return list

  return [
    {
      id: props.booking.id,
      booking_ref: `#SURF-${String(props.booking.id).padStart(5, '0')}`,
      student_name: props.booking.student.name,
      status: 'confirmed',
      is_reserved: false,
      student_count: props.booking.student_count ?? 1,
      duration_hours: props.booking.duration_hours ?? 2,
      start_time: props.booking.start_time ?? null,
      end_time: props.booking.end_time ?? null,
      can_be_changed: true,
      has_pending_change_request: false,
      requires_admin_approval_for_change: props.booking.requires_admin_approval_for_change,
    },
  ]
})

const selectableSiblings = computed(() =>
  normalizedSessionBookings.value.filter(isSiblingSelectable),
)

const hasSharedSession = computed(() => normalizedSessionBookings.value.length > 1)

const effectiveSelectedIds = computed<number[]>(() => {
  if (!props.booking) return []
  if (!hasSharedSession.value || scopeMode.value === 'single') {
    return [props.booking.id]
  }
  const valid = selectedIds.value.filter((id) =>
    selectableSiblings.value.some((member) => member.id === id),
  )
  return valid.length > 0 ? valid : [props.booking.id]
})

const totalSelectedSeats = computed(() => {
  const byId = new Map(normalizedSessionBookings.value.map((m) => [m.id, m]))
  return Math.max(
    1,
    effectiveSelectedIds.value.reduce((sum, id) => sum + (byId.get(id)?.student_count ?? 1), 0),
  )
})

const needsAdmin = computed(() => {
  if (hasSharedSession.value && scopeMode.value === 'session') {
    const selectedSet = new Set(effectiveSelectedIds.value)
    return normalizedSessionBookings.value.some(
      (member) => selectedSet.has(member.id) && Boolean(member.requires_admin_approval_for_change),
    )
  }

  return props.requiresAdminApproval ?? props.booking?.requires_admin_approval_for_change ?? false
})

const allSlots = computed<StartSlotOption[]>(() => [
  ...(availability.value?.periods?.morning?.slots ?? []),
  ...(availability.value?.periods?.afternoon?.slots ?? []),
])

const selectedSlot = computed<StartSlotOption | null>(
  () => allSlots.value.find((slot) => slot.start_time === form.start_time) ?? null,
)

const clock = (value?: string | null) => {
  if (!value) return null
  const time = value.includes('T') ? value.split('T')[1] : value
  const [hour, minute] = time.slice(0, 5).split(':').map(Number)
  if (Number.isNaN(hour) || Number.isNaN(minute)) return null
  const suffix = hour >= 12 ? 'PM' : 'AM'
  const displayHour = hour % 12 === 0 ? 12 : hour % 12
  return `${displayHour}:${String(minute).padStart(2, '0')} ${suffix}`
}

const currentSlotSummary = computed(() => {
  if (!props.booking) return ''
  const dateLabel = formatPhilippineDate(props.booking.date, { month: 'short', day: 'numeric', year: 'numeric' }, 'en-US', props.booking.date)
  const start = clock(props.booking.start_time)
  const end = clock(props.booking.end_time)
  const time = start && end ? `${start} – ${end}` : props.booking.time_period
  return `${dateLabel} · ${time}`
})

const loadSlotsForDate = async (date: string) => {
  if (!props.booking || !date) {
    availability.value = null
    return
  }

  loadingSlots.value = true
  slotError.value = null

  try {
    const { data } = await axios.get<RescheduleAvailabilityResponse>(
      route('instructor.bookings.reschedule-availability', props.booking.id),
      {
        params: {
          date,
          booking_ids: effectiveSelectedIds.value,
        },
      },
    )
    availability.value = data

    const validNonCurrent = [
      ...(data.periods?.morning?.slots ?? []),
      ...(data.periods?.afternoon?.slots ?? []),
    ].filter((s) => !s.is_current)

    if (!validNonCurrent.some((s) => s.start_time === form.start_time)) {
      form.start_time = validNonCurrent[0]?.start_time ?? ''
    }
  } catch {
    availability.value = null
    slotError.value = 'Could not load open start times for that date.'
    form.start_time = ''
  } finally {
    loadingSlots.value = false
  }
}

const initSelection = () => {
  if (!props.booking) return
  const eligible = selectableSiblings.value.map((m) => m.id)
  if (eligible.length > 1) {
    scopeMode.value = 'session'
    selectedIds.value = eligible
  } else {
    scopeMode.value = 'single'
    selectedIds.value = [props.booking.id]
  }
}

const toggleMember = (id: number) => {
  if (selectedIds.value.includes(id)) {
    if (selectedIds.value.length <= 1) return
    selectedIds.value = selectedIds.value.filter((item) => item !== id)
  } else {
    selectedIds.value = [...selectedIds.value, id]
  }
}

watch(
  () => [props.show, props.booking?.id] as const,
  ([isOpen]) => {
    if (!isOpen || !props.booking) return
    form.reset()
    form.clearErrors()
    form.type = 'reschedule'
    form.date = String(props.booking.date).slice(0, 10)
    form.start_time = ''
    form.reason = ''
    initSelection()
    loadSlotsForDate(form.date)
  },
)

watch(
  () => form.date,
  (newDate, oldDate) => {
    if (!props.show || !props.booking || !newDate || newDate === oldDate) return
    form.start_time = ''
    loadSlotsForDate(newDate)
  },
)

watch(
  () => [scopeMode.value, effectiveSelectedIds.value.join(',')] as const,
  () => {
    if (!props.show || !props.booking || !form.date) return
    loadSlotsForDate(form.date)
  },
)

const submit = () => {
  if (!props.booking) return

  form.booking_ids = effectiveSelectedIds.value

  form.post(route('instructor.bookings.change-request', props.booking.id), {
    preserveScroll: true,
    onSuccess: () => {
      emit('close')
      form.reset()
    },
  })
}
</script>

<template>
  <Modal :show="show" @close="emit('close')" maxWidth="2xl">
    <div v-if="booking" class="p-6 space-y-4">
      <div>
        <h2 class="text-title font-semibold text-deep-950">
          {{ effectiveSelectedIds.length > 1 ? `Reschedule shared session (${effectiveSelectedIds.length} students)` : 'Reschedule this lesson' }}
        </h2>
        <p class="mt-1 text-body-sm text-stone-600">
          Choose the new date and start time. Each booking keeps its booked lesson length and the period &amp; end time update automatically.
        </p>
      </div>

      <div v-if="hasSharedSession" class="rounded-card border border-sand-200 bg-sand-50 p-3.5 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-600">
            Shared session scope ({{ normalizedSessionBookings.length }} surfers in this slot)
          </p>
          <div class="inline-flex rounded-control border border-sand-300 bg-white p-0.5 text-label font-semibold">
            <button
              type="button"
              class="rounded px-2.5 py-1 transition-colors"
              :class="scopeMode === 'session' ? 'bg-deep-950 text-white' : 'text-stone-600 hover:text-deep-950'"
              :disabled="selectableSiblings.length === 0"
              @click="scopeMode = 'session'"
            >
              Entire session ({{ selectableSiblings.length }})
            </button>
            <button
              type="button"
              class="rounded px-2.5 py-1 transition-colors"
              :class="scopeMode === 'single' ? 'bg-deep-950 text-white' : 'text-stone-600 hover:text-deep-950'"
              @click="scopeMode = 'single'"
            >
              {{ booking.student.name }} only
            </button>
          </div>
        </div>

        <div v-if="scopeMode === 'session'" class="flex flex-wrap gap-2">
          <label
            v-for="member in normalizedSessionBookings"
            :key="member.id"
            :title="siblingDisabledReason(member) ?? undefined"
            class="inline-flex items-center gap-2 rounded-control border px-2.5 py-1.5 text-label transition-colors"
            :class="
              !isSiblingSelectable(member)
                ? 'cursor-not-allowed border-sand-200 bg-sand-100 text-stone-400'
                : effectiveSelectedIds.includes(member.id)
                  ? 'cursor-pointer border-lagoon-600 bg-lagoon-50 text-deep-950 font-semibold'
                  : 'cursor-pointer border-sand-300 bg-white text-stone-600 hover:border-lagoon-500'
            "
          >
            <input
              type="checkbox"
              class="h-3.5 w-3.5 rounded border-sand-300 text-lagoon-700 focus:ring-lagoon-600"
              :checked="effectiveSelectedIds.includes(member.id)"
              :disabled="!isSiblingSelectable(member) || (effectiveSelectedIds.includes(member.id) && effectiveSelectedIds.length <= 1)"
              @change="toggleMember(member.id)"
            />
            <span>{{ member.student_name }}</span>
            <span v-if="member.duration_hours" class="text-[11px] opacity-75">({{ member.duration_hours }}h)</span>
            <span v-if="siblingDisabledReason(member)" class="text-[11px] text-amber-800">· {{ siblingDisabledReason(member) }}</span>
          </label>
        </div>

        <p v-else class="rounded-control border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-label text-amber-900">
          Moving only {{ booking.student.name }} will separate them from the other students in this shared slot.
        </p>
      </div>

      <AppPanel tone="neutral" label="How rescheduling works">
        <template v-if="effectiveSelectedIds.length > 1">
          All {{ effectiveSelectedIds.length }} selected students will receive an individual reschedule proposal and your reason in their own private Messages thread. Each student's booking only moves after they agree.
        </template>
        <template v-else>
          {{ booking.student.name }} must be notified and agree to the new schedule before the booking moves. Your reason
          will also be sent directly to Messages so you can talk first.
        </template>
        <span v-if="needsAdmin" class="mt-1 block font-semibold text-amber-900">
          Because the original lesson starts within 24 hours, SISA Admin approval will also run after the student(s) agree.
        </span>
      </AppPanel>

      <div class="grid gap-4 lg:grid-cols-12">
        <div class="lg:col-span-7">
          <p class="mb-2 text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
            1. Choose new date
          </p>
          <AvailabilityCalendar
            v-model="form.date"
            :instructor-id="booking.instructor_id"
            :students="totalSelectedSeats"
            :skill-level="booking.skill_level ?? undefined"
            :session-type="booking.session_type ?? undefined"
            :except-booking-ids="effectiveSelectedIds"
            :surf-spot-id="booking.surf_spot_id ?? undefined"
          />
          <p v-if="form.errors.date" class="mt-1 text-body-sm font-semibold text-rose-700">
            {{ form.errors.date }}
          </p>
        </div>

        <div class="space-y-4 lg:col-span-5">
          <div>
            <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
              2. Choose start time
            </p>
            <p class="mt-0.5 text-label text-stone-500">
              End time &amp; morning/afternoon period fill in automatically.
            </p>

            <p v-if="loadingSlots" class="mt-3 text-body-sm text-stone-500">
              Checking open slots…
            </p>
            <p v-else-if="slotError" class="mt-3 text-body-sm text-rose-700">
              {{ slotError }}
            </p>
            <div v-else-if="availability" class="mt-3 space-y-3">
              <div v-for="periodKey in ['morning', 'afternoon']" :key="periodKey">
                <p class="text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
                  {{ periodKey }}
                </p>
                <div
                  v-if="availability.periods[periodKey]?.slots?.length"
                  class="mt-1.5 grid grid-cols-2 gap-1.5"
                >
                  <button
                    v-for="slot in availability.periods[periodKey].slots"
                    :key="slot.start_time"
                    type="button"
                    :disabled="slot.is_current"
                    class="rounded-control border px-2.5 py-2 text-left text-label font-semibold transition-colors"
                    :class="
                      slot.is_current
                        ? 'cursor-not-allowed border-sand-200 bg-sand-100 text-stone-400'
                        : form.start_time === slot.start_time
                          ? 'border-lagoon-700 bg-lagoon-700 text-white'
                          : 'border-sand-300 bg-white text-deep-900 hover:border-lagoon-600 hover:bg-lagoon-50'
                    "
                    @click="form.start_time = slot.start_time"
                  >
                    <span class="block">{{ slot.start_label }}</span>
                    <span class="block text-[11px] font-normal opacity-80">
                      {{ slot.is_current ? 'Current slot' : `to ${slot.end_label}` }}
                    </span>
                  </button>
                </div>
                <p v-else class="mt-1 text-label text-stone-500">
                  {{ availability.periods[periodKey]?.reason ?? 'No slots open.' }}
                </p>
              </div>
            </div>
            <p v-if="form.errors.start_time" class="mt-1 text-body-sm font-semibold text-rose-700">
              {{ form.errors.start_time }}
            </p>
          </div>

          <div class="rounded-control border border-sand-200 bg-sand-50 p-3 text-label space-y-1">
            <p class="font-semibold uppercase tracking-[0.06em] text-stone-500">Automated schedule transfer</p>
            <p class="text-stone-600">Current: <span class="font-semibold text-deep-900">{{ currentSlotSummary }}</span></p>
            <p v-if="selectedSlot" class="text-lagoon-800">
              Proposed:
              <span class="font-semibold">
                {{ formatPhilippineDate(form.date, { month: 'short', day: 'numeric', year: 'numeric' }, 'en-US', form.date) }}
                · {{ selectedSlot.start_label }} – {{ selectedSlot.end_label }}
                ({{ selectedSlot.time_period }})
              </span>
            </p>
            <p v-else class="text-stone-500">Select an available start time above.</p>
          </div>
        </div>
      </div>

      <div>
        <label for="reschedule-reason" class="block text-label font-semibold uppercase tracking-[0.06em] text-stone-500">
          Reason for rescheduling (sent to Messages) <span class="text-rose-600">*</span>
        </label>
        <textarea
          id="reschedule-reason"
          v-model="form.reason"
          rows="3"
          maxlength="500"
          required
          placeholder="Explain why you need to move this lesson (e.g., better tide window, equipment swap)…"
          class="mt-2 w-full rounded-control border border-sand-200 bg-white px-3 py-2.5 text-body text-deep-900 focus:border-lagoon-600 focus:ring-lagoon-600"
        ></textarea>
        <p v-if="form.errors.reason" class="mt-1 text-body-sm font-semibold text-rose-700">
          {{ form.errors.reason }}
        </p>
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <AppButton variant="secondary" :disabled="form.processing" @click="emit('close')">
          Keep current slot
        </AppButton>
        <AppButton
          :disabled="!form.date || !form.start_time || !form.reason.trim() || effectiveSelectedIds.length === 0"
          :loading="form.processing"
          @click="submit"
        >
          {{ effectiveSelectedIds.length > 1 ? `Send reschedule proposal (${effectiveSelectedIds.length} students)` : 'Send reschedule proposal' }}
        </AppButton>
      </div>
    </div>
  </Modal>
</template>
