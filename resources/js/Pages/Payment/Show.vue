<script setup lang="ts">
/**
 * Payment/Show — the standalone payment page.
 *
 * This page was missing entirely: PaymentController::show() rendered
 * `Payment/Show` while only `Booking/Payment.vue` existed, so /payments/{booking}
 * failed. It now mirrors the booking payment step and surfaces the GCash
 * checkout link the acceptance flow generates.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AppSidebarLayout from '@/Layouts/AppSidebarLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import Badge from '@/Components/Atoms/Badge.vue'
import { formatDate, formatDateTime } from '@/utils/date'
import {
  CreditCardIcon,
  BanknotesIcon,
  ArrowDownTrayIcon,
  ClockIcon,
  MapPinIcon,
  CheckCircleIcon,
} from '@heroicons/vue/24/outline'

interface Props {
  booking: {
    id: number
    date: string
    time_period: string
    start_time?: string | null
    end_time?: string | null
    status: string
    total_amount: number
    payment_status?: string
    payment_deadline_at?: string | null
    instructor?: { name: string } | null
    surf_spot?: { name: string; area?: string | null } | null
    payment?: {
      amount: number
      payment_method: string
      status: string
      paid_at?: string | null
      checkout_url?: string | null
      transaction_id?: string | null
    } | null
  }
}

const props = defineProps<Props>()

const processing = ref(false)

const payment = computed(() => props.booking.payment)
const isPaid = computed(
  () => payment.value?.status === 'completed' || props.booking.payment_status === 'completed',
)
const isCash = computed(() => payment.value?.payment_method === 'cash')
const refundStatuses = new Set(['refund_requested', 'refund_processing', 'refunded'])
const isRefundState = computed(
  () => refundStatuses.has(payment.value?.status ?? '') || refundStatuses.has(props.booking.payment_status ?? ''),
)
const isRefundCompleted = computed(
  () => payment.value?.status === 'refunded' || props.booking.payment_status === 'refunded',
)
const isCancelledBooking = computed(() =>
  ['cancelled', 'declined', 'expired', 'payment_timeout', 'no_show'].includes(props.booking.status ?? ''),
)
const isWaitingForInstructor = computed(() =>
  ['reserved', 'pending'].includes(props.booking.status ?? ''),
)
const paymentExpired = computed(() => {
  if (!props.booking.payment_deadline_at) return false
  return new Date(props.booking.payment_deadline_at).getTime() <= Date.now()
})
const canPayOnline = computed(
  () =>
    !isPaid.value &&
    !isCash.value &&
    props.booking.status === 'awaiting_payment' &&
    !paymentExpired.value,
)

const money = (value: number) =>
  new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value ?? 0)

const statusLabel = computed(() => (payment.value?.status ?? 'pending').replace(/_/g, ' '))

const startGCash = () => {
  if (processing.value) return
  processing.value = true

  router.post(route('payments.gcash', props.booking.id), {}, {
    onFinish: () => {
      processing.value = false
    },
  })
}
</script>

<template>
  <Head title="Payment" />

  <AppSidebarLayout>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
      <div class="mb-8">
        <p class="text-xs font-black uppercase tracking-[0.2em] text-teal-600">Payment</p>
        <h1 class="mt-2 text-3xl font-extrabold text-slate-900">
          Booking #SURF-{{ String(booking.id).padStart(5, '0') }}
        </h1>
      </div>

      <div class="overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-slate-900/5">
        <div class="border-b border-slate-100 bg-slate-50/60 px-6 py-5">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p class="text-sm font-semibold text-slate-900">
                {{ booking.instructor?.name ?? 'Instructor' }}
              </p>
              <p class="mt-1 flex items-center gap-1.5 text-xs text-slate-500">
                <MapPinIcon class="h-3.5 w-3.5" />
                {{ booking.surf_spot?.name ?? 'Surf spot' }}
                <span v-if="booking.surf_spot?.area">· {{ booking.surf_spot.area }}</span>
              </p>
            </div>
            <Badge :variant="isPaid ? 'success' : 'warning'" class="capitalize">
              {{ statusLabel }}
            </Badge>
          </div>
        </div>

        <div class="grid gap-6 px-6 py-6 sm:grid-cols-2">
          <div>
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Lesson</p>
            <p class="mt-1 text-sm font-semibold text-slate-900">
              {{ formatDate(booking.date) }}
              <span class="capitalize text-slate-500">· {{ booking.time_period }}</span>
            </p>
            <p v-if="booking.start_time" class="mt-0.5 text-xs text-slate-500">
              Starts {{ booking.start_time }}
            </p>
          </div>

          <div>
            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Amount due</p>
            <p class="mt-1 text-2xl font-extrabold text-slate-900">{{ money(booking.total_amount) }}</p>
          </div>
        </div>

        <div class="border-t border-slate-100 px-6 py-6">
          <div v-if="isPaid" class="flex flex-wrap items-center gap-3">
            <CheckCircleIcon class="h-6 w-6 text-emerald-500" />
            <div class="flex-1">
              <p class="text-sm font-semibold text-slate-900">Payment received</p>
              <p class="text-xs text-slate-500">
                {{ payment?.paid_at ? `Paid ${formatDateTime(payment.paid_at)}` : 'Thank you!' }}
              </p>
            </div>
            <a :href="route('payments.receipt', booking.id)">
              <BaseButton variant="outline" size="sm">
                <ArrowDownTrayIcon class="mr-1.5 h-4 w-4" />
                Receipt
              </BaseButton>
            </a>
          </div>

          <div v-else-if="isCash" class="flex items-start gap-3">
            <BanknotesIcon class="mt-0.5 h-6 w-6 text-amber-500" />
            <div>
              <p class="text-sm font-semibold text-slate-900">Cash on hand</p>
              <p class="mt-1 text-xs leading-relaxed text-slate-500">
                Pay your instructor directly at the beach. They will mark the lesson as paid
                after the session.
              </p>
            </div>
          </div>

          <div v-else-if="paymentExpired" class="flex items-start gap-3 rounded-2xl border border-rose-100 bg-rose-50 p-4">
            <ClockIcon class="mt-0.5 h-6 w-6 text-rose-500" />
            <div>
              <p class="text-sm font-semibold text-rose-900">Payment window expired</p>
              <p class="mt-1 text-xs leading-relaxed text-rose-700">
                The 15-minute hold has ended. Return to your booking to rebook or contact SISA support.
              </p>
            </div>
          </div>

          <div v-else-if="isRefundState" class="flex items-start gap-3 rounded-2xl border border-sky-200 bg-sky-50 p-4">
            <ClockIcon class="mt-0.5 h-6 w-6 shrink-0 text-sky-600" />
            <div class="min-w-0">
              <p class="text-sm font-semibold text-sky-900">
                {{ isRefundCompleted ? 'Payment refunded' : 'Refund in progress' }}
              </p>
              <p class="mt-1 text-xs leading-relaxed text-sky-800">
                {{
                  isRefundCompleted
                    ? 'This payment has been refunded. Check your booking details for the refund breakdown and reference.'
                    : 'Your refund request is being processed. Return to your booking details for the latest refund status.'
                }}
              </p>
            </div>
          </div>

          <div v-else-if="isCancelledBooking" class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <ClockIcon class="mt-0.5 h-6 w-6 shrink-0 text-slate-500" />
            <div class="min-w-0">
              <p class="text-sm font-semibold text-slate-900">Booking no longer open for payment</p>
              <p class="mt-1 text-xs leading-relaxed text-slate-600">
                This booking has already closed. Return to your booking details to review its final status or rebook.
              </p>
            </div>
          </div>

          <div v-else-if="isWaitingForInstructor || booking.status !== 'awaiting_payment'" class="flex items-start gap-3 rounded-2xl border border-amber-100 bg-amber-50 p-4">
            <ClockIcon class="mt-0.5 h-6 w-6 shrink-0 text-amber-500" />
            <div class="min-w-0">
              <p class="text-sm font-semibold text-amber-900">Waiting for the instructor</p>
              <p class="mt-1 text-xs leading-relaxed text-amber-700">
                Payment becomes available after the instructor accepts this booking.
              </p>
            </div>
          </div>

          <div v-else class="space-y-4">
            <div class="flex items-start gap-3">
              <CreditCardIcon class="mt-0.5 h-6 w-6 text-teal-600" />
              <div class="flex-1">
                <p class="text-sm font-semibold text-slate-900">Pay with GCash</p>
                <p class="mt-1 text-xs leading-relaxed text-slate-500">
                  You will be redirected to PayMongo. In local development the mock checkout
                  stands in for GCash.
                </p>
              </div>
            </div>

            <BaseButton
              variant="primary"
              size="lg"
              class="w-full justify-center"
              :loading="processing"
              :disabled="!canPayOnline"
              @click="startGCash"
            >
              <CreditCardIcon class="mr-2 h-5 w-5" />
              Pay {{ money(booking.total_amount) }} with GCash
            </BaseButton>

            <p v-if="booking.payment_deadline_at" class="flex items-center justify-center gap-1.5 text-xs text-amber-600">
              <ClockIcon class="h-3.5 w-3.5" />
              Payment must complete before the hold expires.
            </p>
          </div>
        </div>
      </div>

      <div class="mt-6 flex flex-wrap items-center justify-between gap-3 text-sm">
        <Link :href="route('student.bookings.show', booking.id)" class="font-semibold text-teal-700 hover:text-teal-800">
          ← Back to booking
        </Link>
        <Link :href="route('student.bookings.index')" class="text-slate-500 hover:text-slate-700">
          All my bookings
        </Link>
      </div>
    </div>
  </AppSidebarLayout>
</template>
