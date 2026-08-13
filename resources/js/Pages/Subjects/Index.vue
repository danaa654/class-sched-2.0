<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    subjects: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    filters: {
        type: Object,
        default: () => ({ subject_search: '' }),
    },
    majors: { type: Array, default: () => [] },
    roomCategories: { type: Array, default: () => [] },
});

const toast = useToast();
const page = usePage();

// Show a toast whenever the backend flashes a success/error message.
watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) {
            toast.add({ severity: 'success', summary: 'Success', detail: message, life: 4000 });
        }
    },
);
watch(
    () => page.props.flash?.error,
    (message) => {
        if (message) {
            toast.add({ severity: 'error', summary: 'Error', detail: message, life: 4000 });
        }
    },
);

/* ------------------------------------------------------------------ */
/* Search / list                                                       */
/* ------------------------------------------------------------------ */

const search = ref(props.filters.subject_search ?? '');
const loading = ref(false);
let searchDebounce = null;

const reloadSubjects = (extra = {}) => {
    loading.value = true;

    router.get(
        route('subjects'),
        { subject_search: search.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['subjects'],
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        reloadSubjects({ subject_page: 1 });
    }, 350);
});

const onPage = (event) => {
    reloadSubjects({ subject_page: event.page + 1 });
};

const onRefresh = () => {
    reloadSubjects({ subject_page: props.subjects.current_page });
};

/* ------------------------------------------------------------------ */
/* Add Subject                                                         */
/* ------------------------------------------------------------------ */

const categoryOptions = ['Major', 'General Education'];
const statusOptions = [
    { label: 'Active', value: true },
    { label: 'Inactive', value: false },
];

const addSubjectVisible = ref(false);
const editingSubject = ref(null); // null => Add mode, otherwise the Subject being edited

const subjectForm = useForm({
    subject_code: '',
    subject_title: '',
    major_id: null,
    category: null,
    units: 0,
    lecture_hours: 0,
    laboratory_hours: 0,
    preferred_room_category: null,
    is_active: true,
    description: '',
});

// Major is only meaningful when Subject Type is "Major".
watch(
    () => subjectForm.category,
    (category) => {
        if (category === 'General Education') {
            subjectForm.major_id = null;
        }
    },
);

const openAdd = () => {
    editingSubject.value = null;
    subjectForm.reset();
    subjectForm.clearErrors();
    addSubjectVisible.value = true;
};

const openEdit = (subject) => {
    editingSubject.value = subject;
    subjectForm.clearErrors();
    subjectForm.subject_code = subject.subject_code;
    subjectForm.subject_title = subject.subject_title;
    subjectForm.major_id = subject.major_id;
    subjectForm.category = subject.category;
    subjectForm.units = subject.units;
    subjectForm.lecture_hours = subject.lecture_hours;
    subjectForm.laboratory_hours = subject.laboratory_hours;
    subjectForm.preferred_room_category = subject.preferred_room_category ?? null;
    subjectForm.is_active = subject.is_active;
    subjectForm.description = subject.description ?? '';
    addSubjectVisible.value = true;
};

const closeAddSubject = () => {
    addSubjectVisible.value = false;
    editingSubject.value = null;
    subjectForm.reset();
    subjectForm.clearErrors();
};

const onSaveSubject = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            const wasEditing = !!editingSubject.value;
            closeAddSubject();
            Swal.fire({
                title: wasEditing ? 'Subject updated' : 'Subject saved',
                text: wasEditing
                    ? 'The subject was updated successfully.'
                    : 'The subject was created successfully.',
                icon: 'success',
                confirmButtonColor: '#16A34A',
            });
            onRefresh();
        },
        onError: () => {
            toast.add({
                severity: 'warn',
                summary: 'Missing information',
                detail: 'Please check the highlighted fields and try again.',
                life: 3000,
            });
        },
    };

    if (editingSubject.value) {
        subjectForm.put(route('subjects.update', editingSubject.value.id), options);
    } else {
        subjectForm.post(route('subjects.store'), options);
    }
};

const onDeleteSubject = (subject) => {
    Swal.fire({
        title: 'Delete this subject?',
        text: `${subject.subject_code} — ${subject.subject_title} will be permanently deleted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('subjects.destroy', subject.id), {
                preserveScroll: true,
                onSuccess: () => onRefresh(),
            });
        }
    });
};
</script>

<template>
    <Head title="Subjects" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Subjects</span>
        </template>

        <div class="max-w-7xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Page Title -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">Subjects</h1>
                <p class="mt-1 text-slate-500">
                    Manage the master list of all subjects offered by the institution.
                </p>
            </div>

            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <!-- Top Toolbar -->
                    <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3">
                        <template #start>
                            <span class="relative w-full sm:w-80">
                                <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <InputText
                                    v-model="search"
                                    placeholder="Search by code, title, category or major"
                                    class="w-full !pl-9"
                                />
                            </span>
                        </template>
                        <template #end>
                            <div class="flex items-center gap-2">
                                <Button
                                    icon="pi pi-refresh"
                                    severity="secondary"
                                    outlined
                                    :loading="loading"
                                    @click="onRefresh"
                                    aria-label="Refresh"
                                />
                                <Button label="Add Subject" icon="pi pi-plus" severity="success" @click="openAdd" />
                            </div>
                        </template>
                    </Toolbar>

                    <!-- Subjects Table -->
                    <DataTable
                        :value="subjects.data"
                        :loading="loading"
                        dataKey="id"
                        class="rounded-xl overflow-hidden"
                        stripedRows
                        responsiveLayout="scroll"
                        lazy
                        paginator
                        :rows="subjects.per_page"
                        :totalRecords="subjects.total"
                        :first="(subjects.current_page - 1) * subjects.per_page"
                        @page="onPage"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No subjects found.</p>
                                <p class="text-slate-400 text-sm mt-1">
                                    Click "Add Subject" to create your first subject.
                                </p>
                                <Button
                                    label="Add Subject"
                                    icon="pi pi-plus"
                                    severity="success"
                                    class="mt-3"
                                    @click="openAdd"
                                />
                            </div>
                        </template>

                        <Column field="subject_code" header="Subject Code" style="width: 10rem" />
                        <Column field="subject_title" header="Subject Title" />
                        <Column header="Category" style="width: 11rem">
                            <template #body="{ data }">
                                {{ data.category }}
                            </template>
                        </Column>
                        <Column header="Major" style="width: 14rem">
                            <template #body="{ data }">
                                {{ data.major?.name || '—' }}
                            </template>
                        </Column>
                        <Column header="Units" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.units }}
                            </template>
                        </Column>
                        <Column header="Lecture Hours" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.lecture_hours }}
                            </template>
                        </Column>
                        <Column header="Lab Hours" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.laboratory_hours }}
                            </template>
                        </Column>
                        <Column header="Preferred Room" style="width: 12rem">
                            <template #body="{ data }">
                                {{ data.preferred_room_category || '—' }}
                            </template>
                        </Column>
                        <Column header="Status" style="width: 9rem">
                            <template #body="{ data }">
                                <Tag
                                    :value="data.is_active ? 'Active' : 'Inactive'"
                                    :severity="data.is_active ? 'success' : 'secondary'"
                                />
                            </template>
                        </Column>
                        <Column header="Actions" style="width: 9rem">
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <Button
                                        icon="pi pi-pencil"
                                        text
                                        rounded
                                        severity="secondary"
                                        size="small"
                                        aria-label="Edit"
                                        @click="openEdit(data)"
                                    />
                                    <Button
                                        icon="pi pi-trash"
                                        text
                                        rounded
                                        severity="danger"
                                        size="small"
                                        aria-label="Delete"
                                        @click="onDeleteSubject(data)"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>

        <!-- Add Subject Dialog -->
        <Dialog
            v-model:visible="addSubjectVisible"
            modal
            :header="editingSubject ? 'Edit Subject' : 'Add Subject'"
            :style="{ width: '700px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{ root: { class: isDark ? 'dark-scope' : '' } }"
            @hide="closeAddSubject"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4" @submit.prevent="onSaveSubject">
                <!-- Subject Code -->
                <div class="flex flex-col gap-1">
                    <label for="subject_code" class="text-sm font-medium text-slate-700">
                        Subject Code <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="subject_code"
                        v-model="subjectForm.subject_code"
                        placeholder="e.g. IT101, GE103, PE1, NSTP2"
                        :invalid="!!subjectForm.errors.subject_code"
                        class="w-full"
                    />
                    <small v-if="subjectForm.errors.subject_code" class="text-red-500">
                        {{ subjectForm.errors.subject_code }}
                    </small>
                </div>

                <!-- Subject Title -->
                <div class="flex flex-col gap-1">
                    <label for="subject_title" class="text-sm font-medium text-slate-700">
                        Subject Title <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="subject_title"
                        v-model="subjectForm.subject_title"
                        placeholder="e.g. Introduction to Computing"
                        :invalid="!!subjectForm.errors.subject_title"
                        class="w-full"
                    />
                    <small v-if="subjectForm.errors.subject_title" class="text-red-500">
                        {{ subjectForm.errors.subject_title }}
                    </small>
                </div>

                <!-- Category -->
                <div class="flex flex-col gap-1">
                    <label for="category" class="text-sm font-medium text-slate-700">
                        Category <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="category"
                        v-model="subjectForm.category"
                        :options="categoryOptions"
                        placeholder="Select a category"
                        :invalid="!!subjectForm.errors.category"
                        class="w-full"
                    />
                    <small v-if="subjectForm.errors.category" class="text-red-500">
                        {{ subjectForm.errors.category }}
                    </small>
                </div>

                <!-- Major -->
                <div class="flex flex-col gap-1">
                    <label for="major_id" class="text-sm font-medium text-slate-700">
                        Major
                        <span v-if="subjectForm.category === 'Major'" class="text-red-500">*</span>
                    </label>
                    <Select
                        id="major_id"
                        v-model="subjectForm.major_id"
                        :options="majors"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Select a major"
                        showClear
                        :disabled="subjectForm.category !== 'Major'"
                        :invalid="!!subjectForm.errors.major_id"
                        class="w-full"
                    />
                    <small v-if="subjectForm.category === 'General Education'" class="text-slate-400">
                        Not applicable for General Education subjects.
                    </small>
                    <small v-else-if="subjectForm.errors.major_id" class="text-red-500">
                        {{ subjectForm.errors.major_id }}
                    </small>
                </div>

                <!-- Units -->
                <div class="flex flex-col gap-1">
                    <label for="units" class="text-sm font-medium text-slate-700">
                        Units <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="units"
                        v-model="subjectForm.units"
                        :min="0"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!subjectForm.errors.units"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="subjectForm.errors.units" class="text-red-500">
                        {{ subjectForm.errors.units }}
                    </small>
                </div>

                <!-- Status -->
                <div class="flex flex-col gap-1">
                    <label for="is_active" class="text-sm font-medium text-slate-700">
                        Status <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="is_active"
                        v-model="subjectForm.is_active"
                        :options="statusOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select status"
                        :invalid="!!subjectForm.errors.is_active"
                        class="w-full"
                    />
                    <small v-if="subjectForm.errors.is_active" class="text-red-500">
                        {{ subjectForm.errors.is_active }}
                    </small>
                </div>

                <!-- Lecture Hours -->
                <div class="flex flex-col gap-1">
                    <label for="lecture_hours" class="text-sm font-medium text-slate-700">
                        Lecture Hours <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="lecture_hours"
                        v-model="subjectForm.lecture_hours"
                        :min="0"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!subjectForm.errors.lecture_hours"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="subjectForm.errors.lecture_hours" class="text-red-500">
                        {{ subjectForm.errors.lecture_hours }}
                    </small>
                </div>

                <!-- Laboratory Hours -->
                <div class="flex flex-col gap-1">
                    <label for="laboratory_hours" class="text-sm font-medium text-slate-700">
                        Laboratory Hours <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="laboratory_hours"
                        v-model="subjectForm.laboratory_hours"
                        :min="0"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!subjectForm.errors.laboratory_hours"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="subjectForm.errors.laboratory_hours" class="text-red-500">
                        {{ subjectForm.errors.laboratory_hours }}
                    </small>
                </div>

                <!-- Preferred Room Category -->
                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label for="preferred_room_category" class="text-sm font-medium text-slate-700">
                        Preferred Room Category
                    </label>
                    <Select
                        id="preferred_room_category"
                        v-model="subjectForm.preferred_room_category"
                        :options="roomCategories"
                        placeholder="e.g. Computer Laboratory, Gymnasium, Classroom"
                        showClear
                        :invalid="!!subjectForm.errors.preferred_room_category"
                        class="w-full"
                    />
                    <small class="text-slate-400">
                        Drives Room Recommendations for this subject (e.g. Computer Programming → Computer Laboratory).
                    </small>
                    <small v-if="subjectForm.errors.preferred_room_category" class="text-red-500">
                        {{ subjectForm.errors.preferred_room_category }}
                    </small>
                </div>

                <!-- Description -->
                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label for="description" class="text-sm font-medium text-slate-700">Description</label>
                    <Textarea
                        id="description"
                        v-model="subjectForm.description"
                        autoResize
                        rows="3"
                        placeholder="Optional notes about this subject"
                        :invalid="!!subjectForm.errors.description"
                        class="w-full"
                    />
                    <small v-if="subjectForm.errors.description" class="text-red-500">
                        {{ subjectForm.errors.description }}
                    </small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeAddSubject" />
                <Button
                    :label="editingSubject ? 'Update Subject' : 'Save Subject'"
                    icon="pi pi-check"
                    severity="success"
                    :loading="subjectForm.processing"
                    @click="onSaveSubject"
                />
            </template>
        </Dialog>
    </AppLayout>
</template>
<style scoped>
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (added conditionally via isDark) recolors the shared light-mode
   Tailwind utility classes and PrimeVue component chrome used
   throughout this page — including inside Dialogs, which PrimeVue
   teleports to <body> but keeps as one contiguous subtree, so these
   descendant selectors still reach them. */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.bg-white) { background-color: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.bg-slate-50) { background-color: rgba(255, 255, 255, 0.04) !important; }
.dark-scope :deep(.bg-slate-100) { background-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }

.dark-scope :deep(.p-card) { background: rgba(255, 255, 255, 0.06) !important; color: #F8FAFC; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-password-input),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect),
.dark-scope :deep(.p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }

.dark-scope :deep(.p-divider.p-divider-horizontal:before) { border-color: rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }

.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.04) !important; color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-datatable-tbody > tr) { background: transparent !important; color: #E2E8F0 !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped) { background: rgba(255, 255, 255, 0.03) !important; }
.dark-scope :deep(.p-datatable-tbody > tr > td) { border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover) { background: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.p-paginator) { background: transparent !important; color: #CBD5E1 !important; }

.dark-scope :deep(.p-menu) { background: #0F1730 !important; border-color: rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-menu .p-menu-item-link) { color: #E2E8F0 !important; }
.dark-scope :deep(.p-menu .p-menu-item-link:hover) { background: rgba(255, 255, 255, 0.06) !important; }
</style>