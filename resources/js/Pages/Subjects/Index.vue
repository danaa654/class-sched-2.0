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
import MultiSelect from 'primevue/multiselect';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';
import Popover from 'primevue/popover';
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    subjects: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    filters: {
        type: Object,
        default: () => ({ subject_search: '' }),
    },
    colleges: { type: Array, default: () => [] },
    majors: { type: Array, default: () => [] },
    roomCategories: { type: Array, default: () => [] },
    subjectAccess: {
        type: Object,
        default: () => ({
            categoryOptions: ['Major', 'General Education', 'Minor'],
            lockedCollegeId: null,
            isCollegeScoped: false,
            isAssistantDean: false,
        }),
    },
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

// Only what this user's role is allowed to pick (server also enforces
// this — see StoreSubjectRequest/UpdateSubjectRequest — this is UI
// convenience only, never the authorization boundary).
const categoryOptions = computed(() => props.subjectAccess.categoryOptions);
const isCollegeLocked = computed(() => props.subjectAccess.isCollegeScoped);
const statusOptions = [
    { label: 'Active', value: true },
    { label: 'Inactive', value: false },
];

const addSubjectVisible = ref(false);
const editingSubject = ref(null); // null => Add mode, otherwise the Subject being edited

const subjectForm = useForm({
    subject_code: '',
    subject_title: '',
    college_id: null,
    major_ids: [],
    category: null,
    // Delivery type: 'regular' (classroom/laboratory) or 'practicum'
    // (Practicum/OJT/Internship/Fieldwork/Clinical Practice, off-campus).
    subject_type: 'regular',
    units: 0,
    lecture_hours: 0,
    laboratory_hours: 0,
    // Practicum/OJT-only fields.
    required_hours: null,
    deployment_type: null,
    deployment_remarks: '',
    preferred_room_category: null,
    is_active: true,
    description: '',
});

const subjectTypeOptions = [
    { label: 'Regular', value: 'regular' },
    { label: 'Practicum / OJT', value: 'practicum' },
];

const deploymentTypeOptions = [
    { label: 'On-Campus', value: 'on_campus' },
    { label: 'Off-Campus', value: 'off_campus' },
];

const isPracticum = computed(() => subjectForm.subject_type === 'practicum');

// Practicum/OJT never occupies a classroom/laboratory — clear the
// preferred room category the moment the type switches, so it can
// never linger on the payload and mislead the scheduling engine.
watch(
    () => subjectForm.subject_type,
    (type) => {
        if (type === 'practicum') {
            subjectForm.preferred_room_category = null;
        } else {
            subjectForm.required_hours = null;
            subjectForm.deployment_type = null;
        }
    },
);

// Only majors belonging to the selected College may be picked.
const majorsForSelectedCollege = computed(() => {
    if (!subjectForm.college_id) return [];
    return props.majors.filter((m) => m.college_id === subjectForm.college_id);
});

// Applicable Major(s) is only meaningful for the "Major" category.
watch(
    () => subjectForm.category,
    (category) => {
        if (category !== 'Major') {
            subjectForm.major_ids = [];
            subjectForm.college_id = isCollegeLocked.value ? props.subjectAccess.lockedCollegeId : null;
        }
    },
);

// Changing the College drops any selected majors that no longer belong to it.
watch(
    () => subjectForm.college_id,
    (collegeId) => {
        subjectForm.major_ids = subjectForm.major_ids.filter((id) =>
            props.majors.some((m) => m.id === id && m.college_id === collegeId),
        );
    },
);

/* ------------------------------------------------------------------ */
/* Bulk Import — Subject Library                                       */
/*                                                                      */
/* Adviser request: adding a whole new curriculum's worth of Subjects  */
/* one-by-one through the Add Subject dialog doesn't scale. Backed by  */
/* SubjectController::import() — every row gets the exact same         */
/* validation + Role/College authorization a manual Add Subject would, */
/* and rows that fail are reported back individually rather than       */
/* aborting the whole file.                                            */
/* ------------------------------------------------------------------ */

const importVisible = ref(false);
const importForm = useForm({ file: null });
const importFileName = ref('');

// Preview overview — read the CSV as soon as it's chosen and show,
// per row, whether the subject_code is brand-new or already exists
// in the master list (e.g. "MMW" is already on file), before anything
// is actually saved. Mirrors the fetch()+X-XSRF-TOKEN pattern used by
// the Sections page's manual-subjects-preview.
const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

const previewRows = ref([]);
const previewLoading = ref(false);
const previewError = ref('');
const previewSummary = computed(() => ({
    new: previewRows.value.filter((r) => r.status === 'new').length,
    exists: previewRows.value.filter((r) => r.status === 'exists').length,
    error: previewRows.value.filter((r) => r.status === 'error').length,
}));
const previewStatusMeta = {
    new: { severity: 'success', label: 'New' },
    exists: { severity: 'warn', label: 'Already exists' },
    error: { severity: 'danger', label: 'Invalid' },
};

const resetPreview = () => {
    previewRows.value = [];
    previewError.value = '';
    previewLoading.value = false;
};

const openImport = () => {
    importForm.reset();
    importForm.clearErrors();
    importFileName.value = '';
    resetPreview();
    importVisible.value = true;
};

const onImportFileChange = async (event) => {
    const file = event.target.files?.[0] ?? null;
    importForm.file = file;
    importFileName.value = file?.name ?? '';
    resetPreview();

    if (!file) return;

    previewLoading.value = true;
    try {
        const body = new FormData();
        body.append('file', file);

        const response = await fetch(route('subjects.import.preview'), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            body,
        });

        const data = await response.json();

        if (!response.ok) {
            previewError.value = data?.error ?? 'Could not read this file. Please check it and try again.';
            return;
        }

        previewRows.value = data.rows ?? [];
    } catch (e) {
        previewError.value = 'Could not reach the server to preview this file.';
    } finally {
        previewLoading.value = false;
    }
};

const submitImport = () => {
    if (!importForm.file) {
        toast.add({ severity: 'warn', summary: 'No file selected', detail: 'Please choose a CSV file first.', life: 4000 });
        return;
    }

    importForm.post(route('subjects.import'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            // The Registrar already reviewed exactly this outcome in
            // the preview overview before clicking Import — the New /
            // Already Exists / Invalid table above IS that review, so
            // there's nothing left to re-surface here. Close the
            // dialog every time; the global success/error toast
            // (watchers above) still reports the final created/
            // skipped/error counts.
            importVisible.value = false;
            importForm.reset();
            importFileName.value = '';
            resetPreview();
        },
    });
};


const openAdd = () => {
    editingSubject.value = null;
    subjectForm.reset();
    subjectForm.clearErrors();
    subjectForm.college_id = isCollegeLocked.value ? props.subjectAccess.lockedCollegeId : null;
    subjectForm.category = props.subjectAccess.isCollegeScoped ? 'Major' : null;
    addSubjectVisible.value = true;
};

const openEdit = (subject) => {
    editingSubject.value = subject;
    subjectForm.clearErrors();
    subjectForm.subject_code = subject.subject_code;
    subjectForm.subject_title = subject.subject_title;
    subjectForm.college_id = isCollegeLocked.value ? props.subjectAccess.lockedCollegeId : (subject.college_id ?? subject.college?.id ?? null);
    subjectForm.major_ids = (subject.majors ?? []).map((m) => m.id);
    subjectForm.category = subject.category;
    subjectForm.subject_type = subject.subject_type ?? 'regular';
    subjectForm.units = subject.units;
    subjectForm.lecture_hours = subject.lecture_hours;
    subjectForm.laboratory_hours = subject.laboratory_hours;
    subjectForm.required_hours = subject.required_hours ?? null;
    subjectForm.deployment_type = subject.deployment_type ?? null;
    subjectForm.deployment_remarks = subject.deployment_remarks ?? '';
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
            <div class="neu-card neu-spotlight relative mb-8 overflow-hidden rounded-2xl">
                <div class="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl">
                    <div class="absolute inset-0" :class="isDark ? 'bg-gradient-to-br from-blue-500/10 via-transparent to-red-500/10' : 'bg-gradient-to-br from-blue-50 via-transparent to-red-50/60'"></div>
                    <div class="absolute -right-10 -top-16 h-44 w-44 rounded-full bg-gradient-to-br" :class="isDark ? 'from-blue-500/20 via-blue-500/5 to-transparent' : 'from-blue-200/80 via-blue-100/40 to-transparent'"></div>
                    <div class="absolute -bottom-20 right-16 h-40 w-40 rounded-full bg-gradient-to-tr" :class="isDark ? 'from-red-500/20 via-red-500/5 to-transparent' : 'from-red-200/70 via-red-100/30 to-transparent'"></div>
                    <div class="absolute -left-10 bottom-0 h-28 w-28 rounded-full bg-gradient-to-tr border-4" :class="isDark ? 'from-blue-500/10 to-transparent border-blue-400/10' : 'from-blue-100/60 to-transparent border-blue-100'"></div>
                    <div class="absolute left-[38%] top-0 h-3 w-3 rounded-full bg-gradient-to-br" :class="isDark ? 'from-red-400/50 to-blue-400/30' : 'from-red-400/80 to-blue-300/60'"></div>
                    <div class="absolute left-[55%] bottom-4 h-20 w-20 rounded-full bg-gradient-to-r blur-xl" :class="isDark ? 'from-blue-500/10 to-red-500/10' : 'from-blue-200/40 to-red-200/40'"></div>
                </div>
                <div class="relative rounded-2xl p-6 transition-colors duration-300">
                <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    Subjects
                    <InfoPopover
                        title="Subjects"
                        :paragraphs="[
                            'The master list of every subject the institution offers — code, title, category, unit load, and lecture/lab hour split.',
                        ]"
                        :bullets="[
                            'Subjects created here become available when building section schedules.',
                             'Required Hours, when set, is checked against a section\'s scheduled lecture/lab hours.',
                            'Deleting a subject only affects future scheduling — it does not remove it from sections that already use it.',
                        ]"
                    />
                </h1>
                <p class="mt-1" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                    Manage the master list of all subjects offered by the institution.
                </p>
                </div>
            </div>

            <div class="neu-card rounded-2xl transition-colors duration-300">
            <Card
                class="!rounded-2xl !bg-transparent !border-0 !shadow-none"
                :pt="{ body: { class: '!bg-transparent' } }"
            >
                <template #content>
                    <!-- Top Toolbar -->
                    <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3">
                        <template #start>
                            <span class="relative w-full sm:w-80">
                                <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                                <InputText
                                    v-uppercase
                                    v-model="search"
                                    placeholder="Search by code, title, category or major"
                                    class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                    :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                />
                            </span>
                        </template>
                        <template #end>
                            <div class="flex items-center gap-2">
                                <Button
                                    icon="pi pi-refresh"
                                    severity="secondary"
                                    text
                                    class="neu-icon-well !rounded-full"
                                    :loading="loading"
                                    @click="onRefresh"
                                    aria-label="Refresh"
                                />
                                <Button label="Import" icon="pi pi-upload" severity="secondary" outlined @click="openImport" />
                                <Button label="Add Subject" icon="pi pi-plus" severity="success" @click="openAdd" />
                            </div>
                        </template>
                    </Toolbar>

                    <!-- Subjects Table -->
                    <DataTable
                        :value="subjects.data"
                        :loading="loading"
                        dataKey="id"
                        class="neu-inset neu-table rounded-xl overflow-hidden"
                        :class="isDark ? 'neu-table-dark' : ''"
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
                                <p class="font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-500'">No subjects found.</p>
                                <p class="text-sm mt-1" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
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
                        <Column header="Subject Type" style="width: 11rem">
                            <template #body="{ data }">
                                <span
                                    v-if="data.subject_type === 'practicum'"
                                    class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800"
                                >
                                    <i class="pi pi-map-marker text-[10px]"></i>
                                    Practicum / OJT
                                </span>
                                <span v-else class="text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Regular</span>
                            </template>
                        </Column>
                        <Column header="College" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.college?.code || '—' }}
                            </template>
                        </Column>
                        <Column header="Major(s)" style="width: 16rem">
                            <template #body="{ data }">
                                {{ data.majors?.length ? data.majors.map((m) => m.code).join(', ') : '—' }}
                            </template>
                        </Column>
                        <Column header="Units" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.units }}
                            </template>
                        </Column>
                        <Column header="Lecture Hours" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.subject_type === 'practicum' ? '—' : data.lecture_hours }}
                            </template>
                        </Column>
                        <Column header="Lab Hours" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.subject_type === 'practicum' ? '—' : data.laboratory_hours }}
                            </template>
                        </Column>
                        <Column header="Required Hours" style="width: 9rem">
                            <template #body="{ data }">
                                {{ data.subject_type === 'practicum' ? (data.required_hours ?? '—') : '—' }}
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
                                <div v-if="data.can_manage" class="flex gap-1">
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
                                <span v-else class="text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">—</span>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
            </div>
        </div>

        <!-- Add Subject Dialog -->
        <Dialog
            v-model:visible="addSubjectVisible"
            modal
            :header="editingSubject ? 'Edit Subject' : 'Add Subject'"
            :style="{ width: '700px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
            @hide="closeAddSubject"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4 neu-form" @submit.prevent="onSaveSubject">
                <!-- Subject Code -->
                <div class="flex flex-col gap-1">
                    <label for="subject_code" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Subject Code <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        v-uppercase
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
                    <label for="subject_title" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Subject Title <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        v-uppercase
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
                    <label for="category" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Category <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="category"
                        v-model="subjectForm.category"
                        :options="categoryOptions"
                        placeholder="Select a category"
                        :disabled="categoryOptions.length === 1"
                        :invalid="!!subjectForm.errors.category"
                        class="w-full"
                    />
                    <small v-if="subjectForm.errors.category" class="text-red-500">
                        {{ subjectForm.errors.category }}
                    </small>
                </div>

                <!-- Subject Type (delivery type) -->
                <div class="flex flex-col gap-1">
                    <label for="subject_type" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Subject Type <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="subject_type"
                        v-model="subjectForm.subject_type"
                        :options="subjectTypeOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select subject type"
                        :invalid="!!subjectForm.errors.subject_type"
                        class="w-full"
                    />
                    <small v-if="isPracticum" class="text-slate-400">
                        Off-campus subject — no classroom/laboratory room will be assigned.
                    </small>
                    <small v-else-if="subjectForm.errors.subject_type" class="text-red-500">
                        {{ subjectForm.errors.subject_type }}
                    </small>
                </div>

                <!-- College -->
                <div class="flex flex-col gap-1">
                    <label for="college_id" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        College
                        <span v-if="subjectForm.category === 'Major'" class="text-red-500">*</span>
                    </label>
                    <Select
                        id="college_id"
                        v-model="subjectForm.college_id"
                        :options="colleges"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Select College"
                        :showClear="!isCollegeLocked"
                        :disabled="isCollegeLocked"
                        :invalid="!!subjectForm.errors.college_id"
                        class="w-full"
                    />
                    <small v-if="isCollegeLocked" class="text-slate-400">
                        Locked to your assigned College.
                    </small>
                    <small v-else-if="subjectForm.errors.college_id" class="text-red-500">
                        {{ subjectForm.errors.college_id }}
                    </small>
                </div>

                <!-- Applicable Major(s) -->
                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label for="major_ids" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Applicable Major(s)
                        <span v-if="subjectForm.category === 'Major'" class="text-red-500">*</span>
                    </label>
                    <MultiSelect
                        id="major_ids"
                        v-model="subjectForm.major_ids"
                        :options="majorsForSelectedCollege"
                        optionLabel="code"
                        optionValue="id"
                        filterBy="code,name"
                        display="chip"
                        filter
                        :disabled="subjectForm.category !== 'Major' || !subjectForm.college_id"
                        placeholder="Select all majors that this subject applies to"
                        :invalid="!!subjectForm.errors.major_ids"
                        class="w-full"
                    >
                        <template #option="{ option }">
                            <span>{{ option.code }}</span>
                            <span class="text-slate-400"> — {{ option.name }}</span>
                        </template>
                    </MultiSelect>
                    <small v-if="subjectForm.category === 'Major' && !subjectForm.college_id" class="text-slate-400">
                        Select a College first.
                    </small>
                    <small v-else-if="subjectForm.category !== 'Major'" class="text-slate-400">
                        Not applicable for GenEd/Minor subjects — shared institution-wide.
                    </small>
                    <small v-else-if="subjectForm.errors.major_ids" class="text-red-500">
                        {{ subjectForm.errors.major_ids }}
                    </small>
                </div>

                <!-- Units -->
                <div class="flex flex-col gap-1">
                    <label for="units" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
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
                    <label for="is_active" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
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

                <!-- Lecture Hours (Regular subjects only) -->
                <div v-if="!isPracticum" class="flex flex-col gap-1">
                    <label for="lecture_hours" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
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

                <!-- Laboratory Hours (Regular subjects only) -->
                <div v-if="!isPracticum" class="flex flex-col gap-1">
                    <label for="laboratory_hours" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
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

                <!-- Required Hours (Practicum/OJT only) -->
                <div v-if="isPracticum" class="flex flex-col gap-1">
                    <label for="required_hours" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Required Hours <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="required_hours"
                        v-model="subjectForm.required_hours"
                        :min="1"
                        placeholder="e.g. 240"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!subjectForm.errors.required_hours"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="subjectForm.errors.required_hours" class="text-red-500">
                        {{ subjectForm.errors.required_hours }}
                    </small>
                </div>

                <!-- Deployment Type (Practicum/OJT only) -->
                <div v-if="isPracticum" class="flex flex-col gap-1">
                    <label for="deployment_type" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Deployment Type <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="deployment_type"
                        v-model="subjectForm.deployment_type"
                        :options="deploymentTypeOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select deployment type"
                        :invalid="!!subjectForm.errors.deployment_type"
                        class="w-full"
                    />
                    <small v-if="subjectForm.errors.deployment_type" class="text-red-500">
                        {{ subjectForm.errors.deployment_type }}
                    </small>
                </div>

                <!-- Deployment / Remarks (Practicum/OJT only) -->
                <div v-if="isPracticum" class="flex flex-col gap-1 sm:col-span-2">
                    <label for="deployment_remarks" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Remarks / Deployment Notes
                    </label>
                    <Textarea
                        id="deployment_remarks"
                        v-model="subjectForm.deployment_remarks"
                        rows="2"
                        placeholder="Optional notes — partner company, supervisor, deployment schedule, etc."
                        :invalid="!!subjectForm.errors.deployment_remarks"
                        class="w-full"
                    />
                    <small v-if="subjectForm.errors.deployment_remarks" class="text-red-500">
                        {{ subjectForm.errors.deployment_remarks }}
                    </small>
                </div>

                <!-- Description -->
                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label for="description" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Description</label>
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

        <!-- Bulk Import Dialog -->
        <Dialog
            v-model:visible="importVisible"
            modal
            header="Import Subjects"
            :style="{ width: '680px' }"
            :breakpoints="{ '640px': '95vw' }"
            :draggable="false"
        >
            <div class="flex flex-col gap-4">
                <p class="text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                    Upload a CSV of subjects to add them all at once — handy when setting up a whole new curriculum
                    instead of adding each subject one by one.
                </p>

                <a
                    :href="route('subjects.import.template')"
                    class="inline-flex w-fit items-center gap-2 text-sm font-medium text-blue-600 hover:underline"
                >
                    <i class="pi pi-download"></i>
                    Download CSV template
                </a>

                <div>
                    <label class="mb-1 block text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">CSV File</label>
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        class="neu-inset w-full rounded-xl border-none p-2 text-sm"
                        :class="isDark ? 'text-slate-200' : ''"
                        @change="onImportFileChange"
                    />
                    <small v-if="importFileName" class="mt-1 block text-slate-400">Selected: {{ importFileName }}</small>
                    <small v-if="importForm.errors.file" class="mt-1 block text-red-500">{{ importForm.errors.file }}</small>
                </div>

                <!-- Preview overview — read straight from the file the
                     moment it's chosen, before anything is saved, so a
                     subject that already exists (e.g. "MMW") is flagged
                     up front instead of only surfacing as an error
                     after Import is clicked. -->
                <div v-if="previewLoading" class="flex items-center gap-2 text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                    <i class="pi pi-spin pi-spinner"></i>
                    Reading file…
                </div>

                <div v-else-if="previewError" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    {{ previewError }}
                </div>

                <div v-else-if="previewRows.length" class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <Tag severity="success" :value="`${previewSummary.new} new`" />
                        <Tag severity="warn" :value="`${previewSummary.exists} already exist`" v-if="previewSummary.exists" />
                        <Tag severity="danger" :value="`${previewSummary.error} invalid`" v-if="previewSummary.error" />
                        <span :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            — subjects already on file will be skipped, not duplicated.
                        </span>
                    </div>

                    <DataTable :value="previewRows" size="small" scrollable scrollHeight="260px" class="text-sm">
                        <Column field="subject_code" header="Code" style="width: 100px" />
                        <Column field="subject_title" header="Title" style="width: 220px" />
                        <Column header="Status" style="min-width: 220px">
                            <template #body="{ data }">
                                <div class="flex flex-col gap-0.5">
                                    <Tag :severity="previewStatusMeta[data.status].severity" :value="previewStatusMeta[data.status].label" class="w-fit" />
                                    <small v-if="data.message" :class="data.status === 'error' ? 'text-red-500' : (isDark ? 'text-slate-400' : 'text-slate-500')">
                                        {{ data.message }}
                                    </small>
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </div>

            </div>

            <template #footer>
                <Button label="Close" severity="secondary" outlined @click="importVisible = false" />
                <Button
                    label="Import"
                    icon="pi pi-upload"
                    severity="success"
                    :loading="importForm.processing"
                    :disabled="previewLoading || (previewRows.length > 0 && previewSummary.new === 0)"
                    @click="submitImport"
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