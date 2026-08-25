<script setup>
/**
 * Reports -> Faculty Schedule -> Send via Email (spec section 3).
 * Opened from the Faculty Schedule report row actions with a chosen
 * faculty + the currently selected academic term. Handles the three
 * blocking states (not finalized, missing email, invalid email) as
 * inline banners rather than letting the request fail silently.
 */
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
  show: { type: Boolean, default: false },
  faculty: { type: Object, default: null }, // { id, full_name, faculty_id, email, college_name }
  academicTerm: { type: Object, default: null }, // { id, label }
  isFinalized: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'sent'])

const sending = ref(false)
const serverError = ref('')

const pdfFilename = computed(() => {
  if (!props.faculty) return ''
  const name = (props.faculty.full_name || '').trim().replace(/\s+/g, '_')
  const term = (props.academicTerm?.label || '').replace(/\s+/g, '-')
  return `${name}_Faculty_Schedule_${term}.pdf`
})

const blockedReason = computed(() => {
  if (!props.faculty) return null
  if (!props.faculty.email) return 'missing_email'
  const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  if (!emailPattern.test(props.faculty.email)) return 'invalid_email'
  if (!props.isFinalized) return 'not_finalized'
  return null
})

function close() {
  if (sending.value) return
  serverError.value = ''
  emit('close')
}

function sendSchedule() {
  if (blockedReason.value || sending.value || !props.faculty || !props.academicTerm) return

  sending.value = true
  serverError.value = ''

  router.post('/reports/faculty-schedule/send', {
    faculty_id: props.faculty.id,
    academic_term_id: props.academicTerm.id,
  }, {
    preserveScroll: true,
    onSuccess: () => emit('sent'),
    onError: (errors) => {
      serverError.value = errors.email || errors.schedule || 'Failed to send faculty schedule.'
    },
    onFinish: () => { sending.value = false },
  })
}
</script>

<template>
  <div v-if="show" class="modal-backdrop" @click.self="close">
    <div class="modal-card">
      <h3 class="modal-title">Send Faculty Schedule</h3>

      <div v-if="faculty" class="modal-body">
        <div class="row"><span class="label">Faculty:</span><span>{{ faculty.full_name }}</span></div>
        <div class="row"><span class="label">Faculty ID:</span><span>{{ faculty.faculty_id }}</span></div>
        <div class="row">
          <span class="label">Email:</span>
          <span>{{ faculty.email || 'No email address' }}</span>
        </div>
        <div class="row"><span class="label">Academic Term:</span><span>{{ academicTerm?.label }}</span></div>
        <div class="row">
          <span class="label">Schedule Status:</span>
          <span>{{ isFinalized ? 'Finalized' : 'Not Finalized' }}</span>
        </div>
        <div class="row"><span class="label">Attachment:</span><span>{{ pdfFilename }}</span></div>

        <div v-if="blockedReason === 'not_finalized'" class="banner banner-warning">
          <strong>Schedule Not Finalized</strong>
          <p>This faculty schedule has not been finalized yet. Finalize the schedule before sending it to the faculty member.</p>
        </div>

        <div v-else-if="blockedReason === 'missing_email'" class="banner banner-error">
          <strong>Email Address Required</strong>
          <p>This faculty member does not have an email address. Add an email address to the faculty profile before sending the schedule.</p>
          <a :href="`/faculty/${faculty.id}/edit`" class="link">Edit Faculty</a>
        </div>

        <div v-else-if="blockedReason === 'invalid_email'" class="banner banner-error">
          <strong>Invalid Email Address</strong>
          <p>The email address stored for this faculty member is not valid. Please update the faculty profile.</p>
        </div>

        <div v-if="serverError" class="banner banner-error">{{ serverError }}</div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" :disabled="sending" @click="close">Cancel</button>
        <button
          type="button"
          class="btn btn-primary"
          :disabled="sending || !!blockedReason"
          @click="sendSchedule"
        >
          {{ sending ? 'Sending...' : 'Send Schedule' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop { position: fixed; inset: 0; background: rgba(15, 27, 61, 0.45); display: flex; align-items: center; justify-content: center; z-index: 50; }
.modal-card { background: #fff; border-radius: 10px; padding: 24px; width: 420px; max-width: 92vw; }
.modal-title { margin: 0 0 16px; font-size: 18px; font-weight: 700; }
.row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 14px; }
.label { color: #6b7280; }
.banner { margin-top: 12px; padding: 10px 12px; border-radius: 8px; font-size: 13px; }
.banner-warning { background: #fff7e6; border: 1px solid #ffd58a; }
.banner-error { background: #fdecec; border: 1px solid #f5b5b5; }
.link { display: inline-block; margin-top: 6px; color: #1d4ed8; text-decoration: underline; }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
.btn { padding: 8px 16px; border-radius: 8px; font-size: 14px; border: none; cursor: pointer; }
.btn-secondary { background: #f1f3f9; color: #1f2937; }
.btn-primary { background: #16a34a; color: #fff; }
.btn-primary:disabled, .btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }
</style>