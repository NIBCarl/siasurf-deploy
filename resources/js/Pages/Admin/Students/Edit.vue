<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import BaseButton from '@/Components/Atoms/BaseButton.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'

interface Student {
  id: number
  name: string
  email: string
  phone: string | null
  student_profile: {
    skill_level: string
    birthday?: string | null
    country?: string | null
    status: string
    is_first_time: boolean
    has_board: boolean
    height: string | number | null
    weight: string | number | null
    emergency_contact_name: string | null
    emergency_contact_phone: string | null
    medical_info: string | null
  } | null
}

const props = defineProps<{
  student: Student
  hasActiveBookings: boolean
}>()

const maxBirthday = computed(() => {
  const d = new Date()
  d.setFullYear(d.getFullYear() - 5)
  return d.toISOString().split('T')[0]
})

const form = useForm({
  name: props.student.name,
  email: props.student.email,
  phone: props.student.phone ?? '',
  password: '',
  password_confirmation: '',
  birthday: props.student.student_profile?.birthday ?? '',
  country: props.student.student_profile?.country ?? '',
  skill_level: props.student.student_profile?.skill_level ?? 'beginner',
  status: props.student.student_profile?.status ?? 'active',
  is_first_time: Boolean(props.student.student_profile?.is_first_time ?? true),
  has_board: Boolean(props.student.student_profile?.has_board ?? false),
  height: props.student.student_profile?.height ?? '',
  weight: props.student.student_profile?.weight ?? '',
  emergency_contact_name: props.student.student_profile?.emergency_contact_name ?? '',
  emergency_contact_phone: props.student.student_profile?.emergency_contact_phone ?? '',
  medical_info: props.student.student_profile?.medical_info ?? '',
})

const submit = () => {
  form.put(route('admin.students.update', props.student.id))
}
</script>

<template>
  <AppLayout>
    <Head :title="`Admin: Edit ${student.name}`" />

    <div class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8 space-y-6">
      <div class="flex items-center justify-between">
        <div>
          <nav class="flex mb-2" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-2 text-sm font-medium">
              <li>
                <Link :href="route('admin.students.index')" class="text-slate-400 hover:text-ocean-600 transition-colors">
                  Students
                </Link>
              </li>
              <li class="text-slate-300">/</li>
              <li>
                <Link :href="route('admin.students.show', student.id)" class="text-slate-400 hover:text-ocean-600 transition-colors">
                  {{ student.name }}
                </Link>
              </li>
              <li class="text-slate-300">/</li>
              <li class="font-bold text-slate-900">Edit</li>
            </ol>
          </nav>
          <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Edit Student</h1>
          <p class="mt-1 text-sm text-slate-500">
            Update account details, skill level, and safety information for {{ student.name }}.
          </p>
        </div>

        <Link :href="route('admin.students.show', student.id)" class="text-sm font-bold text-slate-500 hover:text-slate-800">
          Back to Profile
        </Link>
      </div>

      <form class="space-y-6" @submit.prevent="submit">
        <!-- Account Details -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 shadow-sm border border-slate-100 space-y-5">
          <h2 class="text-lg font-extrabold text-slate-900">Account Details</h2>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <InputLabel value="Full Name *" />
              <TextInput v-model="form.name" type="text" class="mt-1 w-full" required />
              <InputError :message="form.errors.name" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Email Address *" />
              <TextInput v-model="form.email" type="email" class="mt-1 w-full" required />
              <InputError :message="form.errors.email" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Phone Number" />
              <TextInput v-model="form.phone" type="text" class="mt-1 w-full" />
              <InputError :message="form.errors.phone" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Account Status *" />
              <select
                v-model="form.status"
                class="mt-1 w-full rounded-lg border-sand-300 bg-white/80 shadow-sm focus:border-ocean-500 focus:ring-ocean-500"
              >
                <option value="active">Active</option>
                <option value="banned">Banned</option>
              </select>
              <InputError :message="form.errors.status" class="mt-1" />
            </div>

            <div>
              <InputLabel value="New Password (optional)" />
              <TextInput v-model="form.password" type="password" class="mt-1 w-full" placeholder="Leave blank to keep current password" />
              <InputError :message="form.errors.password" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Confirm New Password" />
              <TextInput v-model="form.password_confirmation" type="password" class="mt-1 w-full" />
              <InputError :message="form.errors.password_confirmation" class="mt-1" />
            </div>
          </div>
        </div>

        <!-- Surfer Profile -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 shadow-sm border border-slate-100 space-y-5">
          <h2 class="text-lg font-extrabold text-slate-900">Surfer Profile</h2>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <InputLabel value="Date of Birth" />
              <TextInput
                v-model="form.birthday"
                type="date"
                :max="maxBirthday"
                :disabled="hasActiveBookings"
                class="mt-1 w-full disabled:bg-slate-100 disabled:text-slate-500"
              />
              <p v-if="hasActiveBookings" class="mt-1 text-xs font-medium text-amber-700">
                Date of birth is locked while this student has active or upcoming bookings.
              </p>
              <p v-else class="mt-1 text-xs text-slate-500">Surfers must be at least 5 years old.</p>
              <InputError :message="form.errors.birthday" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Country / Origin" />
              <TextInput v-model="form.country" type="text" class="mt-1 w-full" />
              <InputError :message="form.errors.country" class="mt-1" />
            </div>

            <div>
              <InputLabel value="Assessed Skill Level *" />
              <select
                v-model="form.skill_level"
                class="mt-1 w-full rounded-lg border-sand-300 bg-white/80 shadow-sm focus:border-ocean-500 focus:ring-ocean-500"
              >
                <option value="beginner">Beginner</option>
                <option value="intermediate">Intermediate</option>
                <option value="advanced">Advanced</option>
              </select>
              <InputError :message="form.errors.skill_level" class="mt-1" />
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div>
                <InputLabel value="Height (cm)" />
                <TextInput v-model="form.height" type="number" min="50" max="250" step="0.5" class="mt-1 w-full" />
                <InputError :message="form.errors.height" class="mt-1" />
              </div>
              <div>
                <InputLabel value="Weight (kg)" />
                <TextInput v-model="form.weight" type="number" min="15" max="250" step="0.5" class="mt-1 w-full" />
                <InputError :message="form.errors.weight" class="mt-1" />
              </div>
            </div>
          </div>

          <div class="flex flex-wrap gap-6 pt-1">
            <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
              <input v-model="form.is_first_time" type="checkbox" class="rounded border-slate-300 text-ocean-600 focus:ring-ocean-500" />
              First-time surfer
            </label>
            <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
              <input v-model="form.has_board" type="checkbox" class="rounded border-slate-300 text-ocean-600 focus:ring-ocean-500" />
              Brings own surfboard
            </label>
          </div>
        </div>

        <!-- Emergency & Medical -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 shadow-sm border border-slate-100 space-y-5">
          <h2 class="text-lg font-extrabold text-slate-900">Safety &amp; Emergency Contact</h2>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <InputLabel value="Emergency Contact Name" />
              <TextInput v-model="form.emergency_contact_name" type="text" class="mt-1 w-full" />
              <InputError :message="form.errors.emergency_contact_name" class="mt-1" />
            </div>
            <div>
              <InputLabel value="Emergency Contact Phone" />
              <TextInput v-model="form.emergency_contact_phone" type="text" class="mt-1 w-full" />
              <InputError :message="form.errors.emergency_contact_phone" class="mt-1" />
            </div>
          </div>

          <div>
            <InputLabel value="Medical / Health Notes" />
            <textarea
              v-model="form.medical_info"
              rows="3"
              class="mt-1 w-full rounded-lg border-sand-300 bg-white/80 shadow-sm focus:border-ocean-500 focus:ring-ocean-500"
            ></textarea>
            <InputError :message="form.errors.medical_info" class="mt-1" />
          </div>
        </div>

        <div class="flex items-center justify-end gap-3">
          <Link
            :href="route('admin.students.show', student.id)"
            class="px-4 py-2 rounded-lg border border-slate-300 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-50"
          >
            Cancel
          </Link>
          <BaseButton type="submit" variant="primary" :loading="form.processing">
            Save Changes
          </BaseButton>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
