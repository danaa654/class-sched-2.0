<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

/**
 * "Faculty Requests" section (spec Sections 4/17) — Admin/Registrar's
 * review queue for pending Creation/Deletion requests, and Dean/
 * OIC/Assistant Dean's own-submission status tracker (query already
 * scoped server-side — see FacultyController@facultyRequestsProps).
 */
const props = defineProps({
    facultyRequests: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    pendingCount: { type: Number, default: 0 },
    canReview: { type: Boolean, default: false },
    visible: { type: Boolean, default: false },
    // Dean/OIC/Assistant Dean submission actions, surfaced directly on
    // this dialog (mirrors the "New Request" button on the Faculty Load
    // Requests modal) instead of requiring the toolbar/row icons.
    canRequestFacultyCreation: { type: Boolean, default: false },
    canCreateFacultyDirectly: { type: Boolean, default: false },
    deletionFaculties: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:visible', 'request-new-faculty']);

const dialogVisible = computed({
    get: () => props.visible,
    set: (value) => emit('update:visible', value),
});

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');
const toast = useToast();

const statusSeverity = (status) => {
    if (status === 'Pending') return 'warning';
    if (status === 'Approved') return 'success';
    return 'danger';
};

const requestSubjectName = (row) => {
    if (row.request_type === 'Creation') {
        const payload = row.payload ?? {};
        return [payload.first_name, payload.last_name].filter(Boolean).join(' ') || '—';
    }
    return row.faculty ? `${row.faculty.first_name} ${row.faculty.last_name}` : '—';
};

/* Review dialog (Approve/Reject) — Admin/Registrar only. */
const reviewVisible = ref(false);
const reviewingRequest = ref(null);
const reviewForm = useForm({ decision: 'Approved', decision_note: '' });

const openReview = (row, decision) => {
    reviewingRequest.value = row;
    reviewForm.reset();
    reviewForm.decision = decision;
    reviewVisible.value = true;
};

const closeReview = () => {
    reviewVisible.value = false;
    reviewingRequest.value = null;
};

const submitReview = () => {
    if (!reviewingRequest.value) return;
    reviewForm.put(route('scheduling.faculty-requests.review', reviewingRequest.value.id), {
        preserveScroll: true,
        onSuccess: () => closeReview(),
        onError: () => {
            toast.add({ severity: 'error', summary: 'Could not save', detail: 'Check the form and try again.', life: 4000 });
        },
    });
};

/* Cancel a Pending request (requester withdrawing their own submission). */
const cancelRequest = (row) => {
    router.delete(route('scheduling.faculty-requests.cancel', row.id), { preserveScroll: true });
};

/* "Request New Faculty" — delegated to the parent's RequestFacultyModal
   (creation requests carry a fuller form than fits here). */
const requestNewFaculty = () => {
    dialogVisible.value = false;
    emit('request-new-faculty');
};

/* "Request Deletion" — submitted right here, with its own faculty
   picker, so the requester doesn't need to leave this dialog to find
   the row/icon on the Faculty table. */
const deletionVisible = ref(false);
const deletionForm = useForm({ faculty_id: null, reason: '' });

const openDeletionRequest = () => {
    deletionForm.reset();
    deletionForm.clearErrors();
    deletionVisible.value = true;
};

const closeDeletionRequest = () => {
    deletionVisible.value = false;
};

const submitDeletionRequest = () => {
    if (!deletionForm.faculty_id) {
        deletionForm.setError('faculty_id', 'Please select a faculty member.');
        return;
    }
    router.post(
        route('scheduling.faculty-requests.store-deactivation', deletionForm.faculty_id),
        { reason: deletionForm.reason },
        {
            preserveScroll: true,
            onSuccess: () => {
                closeDeletionRequest();
                toast.add({ severity: 'success', summary: 'Request submitted', detail: 'Your deletion request was sent for review.', life: 4000 });
            },
            onError: (errors) => {
                if (errors.reason) deletionForm.setError('reason', errors.reason);
                toast.add({ severity: 'error', summary: 'Could not submit', detail: 'Check the form and try again.', life: 4000 });
            },
        },
    );
};

const onPage = (event) => {
    router.get(
        route('scheduling.faculty'),
        { faculty_requests_page: event.page + 1 },
        { preserveState: true, preserveScroll: true, only: ['facultyRequests'] },
    );
};
</script>

<template>
    <Dialog
        v-model:visible="dialogVisible"
        modal
        header="Faculty Requests"
        :style="{ width: '64rem' }"
        :breakpoints="{ '1200px': '90vw', '640px': '95vw' }"
        :pt="{ root: { class: isDark ? 'dark-scope' : '' } }"
    >
        <template #header>
            <div class="flex flex-1 items-start justify-between gap-4 pr-4">
                <div>
                    <span class="text-xl font-bold flex items-center gap-2" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                        Faculty Requests
                        <Tag v-if="pendingCount > 0" :value="`${pendingCount} pending`" severity="warning" />
                        <InfoPopover
                            title="Faculty Requests"
                            :paragraphs="[
                                'Faculty creation and deletion requests submitted by Dean/OIC/Assistant Dean, for Admin/Registrar review.',
                            ]"
                            :bullets="[
                                canReview
                                    ? 'You can approve or reject requests below — approving creates or permanently deletes the faculty member immediately.'
                                    : 'An Administrator or Registrar reviews these; you\'ll see the decision here once it\'s made.',
                            ]"
                        />
                    </span>
                    <p class="mt-1 text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                        This page doesn't auto-update — refresh or reopen this dialog to see the latest status.
                    </p>
                </div>
                <div v-if="canRequestFacultyCreation && !canCreateFacultyDirectly" class="flex items-center gap-2 shrink-0">
                    <Button label="Request New Faculty" icon="pi pi-plus" size="small" severity="success" @click="requestNewFaculty" />
                    <Button label="Request Deletion" icon="pi pi-send" size="small" severity="danger" outlined @click="openDeletionRequest" />
                </div>
            </div>
        </template>

        <DataTable
            :value="facultyRequests.data"
            dataKey="id"
            class="neu-inset neu-table rounded-xl overflow-hidden"
            :class="isDark ? 'neu-table-dark' : ''"
            stripedRows
            responsiveLayout="scroll"
            :paginator="facultyRequests.total > facultyRequests.per_page"
            :rows="facultyRequests.per_page"
            :totalRecords="facultyRequests.total"
            :first="(facultyRequests.current_page - 1) * facultyRequests.per_page"
            lazy
            @page="onPage"
        >
            <template #empty>No faculty requests yet.</template>
            <Column header="Type">
                <template #body="{ data }">
                    <Tag :value="data.request_type" :severity="data.request_type === 'Creation' ? 'info' : 'secondary'" />
                </template>
            </Column>
            <Column header="Faculty">
                <template #body="{ data }">{{ requestSubjectName(data) }}</template>
            </Column>
            <Column header="College">
                <template #body="{ data }">{{ data.college?.name ?? 'General Education' }}</template>
            </Column>
            <Column header="Requested By">
                <template #body="{ data }">{{ data.requested_by?.name ?? data.requestedBy?.name }}</template>
            </Column>
            <Column header="Reason">
                <template #body="{ data }">
                    <span class="text-sm">{{ data.reason }}</span>
                </template>
            </Column>
            <Column header="Impact" v-if="true">
                <template #body="{ data }">
                    <span v-if="data.request_type === 'Deletion' && data.affected_summary?.has_active_assignments" class="text-amber-600 text-xs flex items-center gap-1">
                        <i class="pi pi-exclamation-triangle" /> {{ data.affected_summary.subject_count }} subject(s), {{ data.affected_summary.section_count }} section(s)
                    </span>
                    <span v-else class="text-xs text-slate-400">—</span>
                </template>
            </Column>
            <Column header="Status">
                <template #body="{ data }">
                    <Tag :value="data.status" :severity="statusSeverity(data.status)" />
                </template>
            </Column>
            <Column header="Actions" v-if="canReview">
                <template #body="{ data }">
                    <div v-if="data.status === 'Pending'" class="flex gap-2">
                        <Button label="Approve" size="small" severity="success" @click="openReview(data, 'Approved')" />
                        <Button label="Reject" size="small" severity="danger" outlined @click="openReview(data, 'Rejected')" />
                    </div>
                    <Button
                        v-else
                        icon="pi pi-times"
                        size="small"
                        text
                        severity="secondary"
                        v-tooltip.top="'Remove from list'"
                        @click="cancelRequest(data)"
                    />
                </template>
            </Column>
        </DataTable>

        <template #footer>
            <Button label="Close" severity="secondary" outlined @click="dialogVisible = false" />
        </template>
    </Dialog>

    <Dialog v-model:visible="reviewVisible" modal :header="`${reviewForm.decision === 'Approved' ? 'Approve' : 'Reject'} Faculty Request`" :style="{ width: '28rem' }">
        <div v-if="reviewingRequest" class="flex flex-col gap-3">
            <p class="text-sm">
                <strong>{{ requestSubjectName(reviewingRequest) }}</strong> — {{ reviewingRequest.request_type }} request.
            </p>
            <div v-if="reviewingRequest.request_type === 'Deletion' && reviewingRequest.affected_summary?.has_active_assignments" class="text-sm bg-amber-50 border border-amber-200 rounded-lg p-3 text-amber-800">
                ⚠ This faculty member currently has {{ reviewingRequest.affected_summary.subject_count }} active subject(s) across
                {{ reviewingRequest.affected_summary.section_count }} section(s) ({{ reviewingRequest.affected_summary.subject_codes?.join(', ') }}).
                Deleting may leave scheduled subjects without an assigned faculty.
            </div>
            <div v-if="reviewingRequest.request_type === 'Deletion' && reviewingRequest.affected_summary?.has_finalized_assignment" class="text-sm bg-red-50 border border-red-200 rounded-lg p-3 text-red-800">
                🔒 Assigned to a finalized schedule ({{ reviewingRequest.affected_summary.finalized_section_codes?.join(', ') }}). Unlock and reassign before approving.
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Decision Note {{ reviewForm.decision === 'Rejected' ? '(required)' : '(optional)' }}</label>
                <Textarea v-model="reviewForm.decision_note" rows="3" autoResize />
                <small v-if="reviewForm.errors.decision_note" class="text-red-500">{{ reviewForm.errors.decision_note }}</small>
            </div>
        </div>
        <template #footer>
            <Button label="Cancel" severity="secondary" outlined @click="closeReview" />
            <Button
                :label="reviewForm.decision === 'Approved' ? 'Confirm Approve' : 'Confirm Reject'"
                :severity="reviewForm.decision === 'Approved' ? 'success' : 'danger'"
                :loading="reviewForm.processing"
                @click="submitReview"
            />
        </template>
    </Dialog>

    <Dialog v-model:visible="deletionVisible" modal header="Request Deletion" :style="{ width: '28rem' }" :pt="{ root: { class: isDark ? 'dark-scope' : '' } }">
        <form class="flex flex-col gap-4" @submit.prevent="submitDeletionRequest">
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                    Faculty Member <span class="text-red-500">*</span>
                </label>
                <Select
                    v-model="deletionForm.faculty_id"
                    :options="deletionFaculties"
                    optionLabel="last_name"
                    optionValue="id"
                    filter
                    placeholder="Select a faculty member"
                    :invalid="!!deletionForm.errors.faculty_id"
                    class="w-full"
                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                >
                    <template #option="{ option }">
                        {{ option.last_name }}, {{ option.first_name }}
                    </template>
                </Select>
                <small v-if="deletionForm.errors.faculty_id" class="text-red-500">{{ deletionForm.errors.faculty_id }}</small>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                    Reason <span class="text-red-500">*</span>
                </label>
                <Textarea v-model="deletionForm.reason" rows="3" autoResize :invalid="!!deletionForm.errors.reason" placeholder="Explain why this faculty member should be deleted…" />
                <small v-if="deletionForm.errors.reason" class="text-red-500">{{ deletionForm.errors.reason }}</small>
            </div>
        </form>
        <template #footer>
            <Button label="Cancel" severity="secondary" outlined @click="closeDeletionRequest" />
            <Button label="Submit Request" icon="pi pi-send" severity="danger" :loading="deletionForm.processing" @click="submitDeletionRequest" />
        </template>
    </Dialog>
</template>