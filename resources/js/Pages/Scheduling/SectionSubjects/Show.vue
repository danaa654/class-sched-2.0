<script setup>
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, reactive, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import DatePicker from 'primevue/datepicker';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import Toast from 'primevue/toast';

const props = defineProps({
    section: { type: Object, required: true },
    sectionSubjects: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ subject_search: '' }) },
    curriculums: { type: Array, default: () => [] },
    availableSubjects: { type: Array, default: () => [] },
    yearLevelMap: { type: Object, default: () => ({}) },
    sectionYearLevel: { type: String, default: null },
    sectionSemester: { type: String, default: null },
    semesterOptions: { type: Array, default: () => [] },
    // Scheduling table options — Faculty/Room dropdowns.
    activeFaculty: { type: Array, default: () => [] },
    activeRooms: { type: Array, default: () => [] },
});

const toast = useToast();
const page = usePage();

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
/* Search                                                               */
/* ------------------------------------------------------------------ */

const search = ref(props.filters.subject_search ?? '');
const loading = ref(false);
let searchDebounce = null;

const reload = (extra = {}) => {
    loading.value = true;

    router.get(
        route('scheduling.section-subjects.show', props.section.id),
        { subject_search: search.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => reload(), 350);
});

const onRefresh = () => reload();

/* ------------------------------------------------------------------ */
/* Scheduling table — rows are a local editable copy of                */
/* sectionSubjects so Faculty/Room/Days/Time cells update instantly    */
/* without waiting on a full Inertia reload. Re-synced whenever the    */
/* server sends a fresh sectionSubjects prop (search, add, remove).    */
/* ------------------------------------------------------------------ */

const toDaysArray = (days) => (days ? days.split(',').filter(Boolean) : []);

const rows = ref(props.sectionSubjects.map((row) => ({ ...row, days: toDaysArray(row.days) })));

watch(
    () => props.sectionSubjects,
    (fresh) => {
        rows.value = fresh.map((row) => ({ ...row, days: toDaysArray(row.days) }));
    },
);

// Per-row, per-field validation error + saving indicator, keyed by row id.
const rowState = reactive({});
const stateFor = (rowId) => {
    if (!rowState[rowId]) {
        rowState[rowId] = { errors: {} };
    }
    return rowState[rowId];
};

/* --- Days --- */

const dayOptions = [
    { label: 'Monday', value: 'Mon' },
    { label: 'Tuesday', value: 'Tue' },
    { label: 'Wednesday', value: 'Wed' },
    { label: 'Thursday', value: 'Thu' },
    { label: 'Friday', value: 'Fri' },
    { label: 'Saturday', value: 'Sat' },
];

// Quick-pick common combinations shown above the multi-select list.
const dayPresets = [
    { label: 'MWF', value: ['Mon', 'Wed', 'Fri'] },
    { label: 'MW', value: ['Mon', 'Wed'] },
    { label: 'TTH', value: ['Tue', 'Thu'] },
    { label: 'WF', value: ['Wed', 'Fri'] },
    { label: 'SAT', value: ['Sat'] },
];

// Compact display like "MWF", "TTH", "SAT" instead of PrimeVue's
// default comma/chip rendering.
const dayAbbreviations = { Mon: 'M', Tue: 'T', Wed: 'W', Thu: 'TH', Fri: 'F', Sat: 'SAT' };
const orderedDayTokens = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const formatDays = (selected) => {
    if (!selected || selected.length === 0) return '';
    return orderedDayTokens
        .filter((token) => selected.includes(token))
        .map((token) => dayAbbreviations[token])
        .join('');
};

const applyDayPreset = (row, preset) => {
    onDaysChange(row, [...preset.value]);
};

/* --- Faculty — every active faculty member, grouped so those            */
/* qualified to teach the subject float to the top, but any active        */
/* faculty can still be picked manually (Registrar override).             */

const isQualifiedFor = (faculty, subject) => {
    if (!subject) return false;
    if (subject.category === 'General Education' && faculty.faculty_category === 'General Education Faculty') {
        return true;
    }
    return faculty.qualified_subject_ids.includes(subject.id);
};

const facultyGroupsFor = (row) => {
    const subject = row.subject;
    const qualified = [];
    const others = [];

    props.activeFaculty.forEach((faculty) => {
        const option = { label: faculty.full_name, value: faculty.id };
        if (isQualifiedFor(faculty, subject)) {
            qualified.push(option);
        } else {
            others.push(option);
        }
    });

    const groups = [];
    if (qualified.length) groups.push({ label: 'Qualified for This Subject', items: qualified });
    if (others.length) groups.push({ label: 'Other Active Faculty (Manual Override)', items: others });
    return groups;
};

/* --- Rooms — every active room, grouped so the type that matches the    */
/* subject's hours (Laboratory rooms if it has lab hours, otherwise       */
/* Lecture rooms) floats to the top. Any active room can still be         */
/* picked manually.                                                       */

const roomGroupsFor = (row) => {
    const subject = row.subject;
    const wantsLaboratory = Number(subject?.laboratory_hours ?? 0) > 0;
    const recommendedType = wantsLaboratory ? 'Laboratory' : 'Lecture';

    const recommended = [];
    const others = [];

    props.activeRooms.forEach((room) => {
        const option = { label: `${room.room_code} — ${room.room_name} (${room.capacity})`, value: room.id };
        if (room.room_type === recommendedType) {
            recommended.push(option);
        } else {
            others.push(option);
        }
    });

    const groups = [];
    if (recommended.length) {
        groups.push({
            label: wantsLaboratory ? 'Laboratory Rooms (Recommended)' : 'Lecture Rooms (Recommended)',
            items: recommended,
        });
    }
    if (others.length) groups.push({ label: 'Other Rooms', items: others });
    return groups;
};

/* --- Time pickers — DatePicker bound to a JS Date, converted to "HH:mm" on save --- */

const timeStringToDate = (value) => {
    if (!value) return null;
    const [hours, minutes] = value.split(':').map(Number);
    const date = new Date();
    date.setHours(hours, minutes, 0, 0);
    return date;
};

const dateToTimeString = (date) => {
    if (!date) return null;
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
};

const startTimeModel = (row) => timeStringToDate(row.start_time);
const endTimeModel = (row) => timeStringToDate(row.end_time);

/* --- Status / conflict badge --- */

const statusSeverity = (status) => {
    switch (status) {
        case 'Scheduled':
            return 'success';
        case 'Conflict':
            return 'danger';
        default:
            return 'secondary';
    }
};

// True once at least one scheduling field is blocked by a Faculty,
// Room, or Time conflict — surfaced as a warning badge next to Status.
const hasActiveConflict = (row) => {
    const errors = stateFor(row.id).errors;
    return Boolean(errors.faculty_id || errors.room_id || errors.days || errors.start_time || errors.end_time);
};

/* --- Manual scheduling — edits stay local until "Save Schedule" is      */
/* clicked. No per-cell auto-save; the Registrar can edit as many rows    */
/* as they like first. Every edited field just updates the local row     */
/* and marks it "dirty"; nothing hits the server until Save.              */

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const dirtyRowIds = ref(new Set());
const hasUnsavedChanges = computed(() => dirtyRowIds.value.size > 0);

const markDirty = (row, field) => {
    dirtyRowIds.value.add(row.id);
    delete stateFor(row.id).errors[field];
};

const onFacultyChange = (row, value) => {
    row.faculty_id = value;
    markDirty(row, 'faculty_id');
};
const onRoomChange = (row, value) => {
    row.room_id = value;
    markDirty(row, 'room_id');
};
const onDaysChange = (row, value) => {
    row.days = value;
    markDirty(row, 'days');
};
const onStartTimeChange = (row, date) => {
    row.start_time = dateToTimeString(date);
    markDirty(row, 'start_time');
};
const onEndTimeChange = (row, date) => {
    row.end_time = dateToTimeString(date);
    markDirty(row, 'end_time');
};

/* --- Save Schedule — validates every row client-side, then saves        */
/* every row in a single request/transaction on the server. Nothing is    */
/* saved unless every row passes. --- */

const savingSchedule = ref(false);

const validateRowsClientSide = () => {
    let valid = true;

    rows.value.forEach((row) => {
        const state = stateFor(row.id);
        state.errors = {};

        if (row.start_time && row.end_time && row.start_time >= row.end_time) {
            state.errors.end_time = 'End Time must be later than Start Time.';
            valid = false;
        }

        // A schedule slot must be fully filled in, or fully empty — no
        // half-assigned rows (e.g. Faculty picked but no Room/Days/Time).
        const filledCount = [row.faculty_id, row.room_id, row.days?.length > 0, row.start_time, row.end_time].filter(
            Boolean,
        ).length;

        if (filledCount > 0 && filledCount < 5) {
            if (!row.faculty_id) state.errors.faculty_id = 'Required to complete this schedule.';
            if (!row.room_id) state.errors.room_id = 'Required to complete this schedule.';
            if (!row.days || row.days.length === 0) state.errors.days = 'Required to complete this schedule.';
            if (!row.start_time) state.errors.start_time = 'Required to complete this schedule.';
            if (!row.end_time) state.errors.end_time = 'Required to complete this schedule.';
            valid = false;
        }
    });

    return valid;
};

const saveSchedule = async () => {
    if (rows.value.length === 0) {
        return;
    }

    if (!validateRowsClientSide()) {
        toast.add({
            severity: 'warn',
            summary: 'Fix the highlighted rows',
            detail: 'Each schedule needs Faculty, Room, Days, Start Time, and End Time filled in together — or all left empty.',
            life: 6000,
        });
        return;
    }

    savingSchedule.value = true;

    const payloadRows = rows.value.map((row) => ({
        id: row.id,
        faculty_id: row.faculty_id || null,
        room_id: row.room_id || null,
        days: row.days ?? [],
        start_time: row.start_time || null,
        end_time: row.end_time || null,
        capacity: row.capacity || null,
    }));

    try {
        const response = await fetch(route('scheduling.section-subjects.schedule.batch', props.section.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ rows: payloadRows }),
        });

        const data = await response.json();

        if (!response.ok) {
            // Errors are keyed by SectionSubject id — map each back to
            // its row so the table highlights exactly what to fix.
            if (data.errors) {
                Object.entries(data.errors).forEach(([rowId, fieldErrors]) => {
                    Object.assign(stateFor(Number(rowId)).errors, fieldErrors);
                });
            }

            toast.add({
                severity: 'error',
                summary: 'Nothing saved',
                detail: data.message ?? 'Some rows have validation errors.',
                life: 7000,
            });
            return;
        }

        rows.value.forEach((row) => {
            const fresh = data.sectionSubjects?.find((r) => r.id === row.id);
            if (fresh) {
                Object.assign(row, { ...fresh, days: toDaysArray(fresh.days) });
            }
        });
        dirtyRowIds.value = new Set();

        toast.add({ severity: 'success', summary: 'Saved', detail: data.message ?? 'Schedule saved successfully.', life: 4000 });
    } catch (e) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Could not save the schedule. Please check your connection and try again.',
            life: 6000,
        });
    } finally {
        savingSchedule.value = false;
    }
};

/* ------------------------------------------------------------------ */
/* Add Subject dialog                                                  */
/* ------------------------------------------------------------------ */

const addDialogVisible = ref(false);
const activeTab = ref('curriculum');

const openAddDialog = () => {
    activeTab.value = 'curriculum';
    curriculumForm.curriculum_id = props.curriculums.length === 1 ? props.curriculums[0].id : null;
    curriculumForm.year_level = props.sectionYearLevel;
    curriculumForm.semester = props.sectionSemester;
    curriculumPreviewRows.value = [];
    hasPreviewed.value = false;
    manualForm.reset();
    manualForm.clearErrors();
    addDialogVisible.value = true;
};

const closeAddDialog = () => {
    addDialogVisible.value = false;
    curriculumPreviewRows.value = [];
    hasPreviewed.value = false;
};

/* --- Option 1: Load From Curriculum --- */

const yearLevelOptions = computed(() => Object.values(props.yearLevelMap).map((label) => ({ label, value: label })));
const semesterOptions = computed(() => props.semesterOptions.map((label) => ({ label, value: label })));

const curriculumForm = useForm({
    curriculum_id: null,
    year_level: null,
    semester: null,
});

const curriculumPreviewRows = ref([]);
const hasPreviewed = ref(false);
const previewLoading = ref(false);
const previewError = ref('');

const onPreview = async () => {
    previewError.value = '';

    if (!curriculumForm.curriculum_id || !curriculumForm.year_level || !curriculumForm.semester) {
        previewError.value = 'Select a curriculum, year level, and semester first.';
        return;
    }

    previewLoading.value = true;

    try {
        const { data } = await window.axios.get(
            route('scheduling.section-subjects.curriculum-preview', props.section.id),
            {
                params: {
                    curriculum_id: curriculumForm.curriculum_id,
                    year_level: curriculumForm.year_level,
                    semester: curriculumForm.semester,
                },
            },
        );
        curriculumPreviewRows.value = data.subjects;
        hasPreviewed.value = true;

        if (data.subjects.length === 0) {
            previewError.value = 'No new subjects found for this curriculum, year level, and semester.';
        }
    } catch (error) {
        previewError.value = 'Unable to load subjects for that curriculum, year level, and semester.';
    } finally {
        previewLoading.value = false;
    }
};

const removePreviewRow = (subjectId) => {
    curriculumPreviewRows.value = curriculumPreviewRows.value.filter((subject) => subject.id !== subjectId);
};

const curriculumSaving = ref(false);
const curriculumSaveErrors = ref([]);

const onConfirmCurriculumLoad = () => {
    if (curriculumPreviewRows.value.length === 0) {
        return;
    }

    curriculumSaving.value = true;
    curriculumSaveErrors.value = [];

    router.post(
        route('scheduling.section-subjects.store', props.section.id),
        {
            source: 'Curriculum',
            subject_ids: curriculumPreviewRows.value.map((subject) => subject.id),
        },
        {
            preserveScroll: true,
            onSuccess: () => closeAddDialog(),
            onError: (errors) => {
                curriculumSaveErrors.value = Object.values(errors);
            },
            onFinish: () => {
                curriculumSaving.value = false;
            },
        },
    );
};

/* --- Option 2: Manual Selection --- */

const manualForm = useForm({
    subject_ids: [],
});

const manualSubjectOptions = computed(() => props.availableSubjects);

const onAddManual = () => {
    if (manualForm.subject_ids.length === 0) {
        return;
    }

    router.post(
        route('scheduling.section-subjects.store', props.section.id),
        {
            source: 'Manual',
            subject_ids: manualForm.subject_ids,
        },
        {
            preserveScroll: true,
            onSuccess: () => closeAddDialog(),
            onError: (errors) => {
                manualForm.errors.subject_ids = Object.values(errors).flat().join(' ');
            },
        },
    );
};

/* ------------------------------------------------------------------ */
/* Remove Subject                                                      */
/* ------------------------------------------------------------------ */

const onRemove = (row) => {
    Swal.fire({
        title: 'Remove this subject?',
        text: `${row.subject?.subject_code} will be removed from ${props.section.section_code}. The subject itself will not be deleted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, remove it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('scheduling.section-subjects.destroy', [props.section.id, row.subject_id]), {
                preserveScroll: true,
            });
        }
    });
};

const sourceSeverity = (source) => (source === 'Curriculum' ? 'info' : 'secondary');
const categorySeverity = (category) => (category === 'Major' ? 'info' : 'secondary');
</script>

<template>
    <Head :title="`${section.section_code} — Subjects`" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Section Subjects</span>
        </template>

        <div class="max-w-[100rem] mx-auto w-full">
            <!-- Back link -->
            <div class="mb-4">
                <Link :href="route('scheduling.sections')" class="text-sm text-slate-500 hover:text-slate-700">
                    <i class="pi pi-arrow-left mr-1"></i> Back to Sections
                </Link>
            </div>

            <!-- Page Title -->
            <div class="mb-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">
                        {{ section.section_code }} — {{ section.section_name }}
                    </h1>
                    <p class="mt-1 text-slate-500">
                        Build this section's subject list and assign Faculty, Room, Days, and Time directly in the
                        table. Edit as many rows as you like, then click "Save Schedule" to save everything at once.
                        Automatic scheduling is coming soon — this is manual only.
                    </p>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <span v-if="hasUnsavedChanges" class="text-sm text-amber-600 font-medium whitespace-nowrap">
                        <i class="pi pi-circle-fill text-[6px] align-middle mr-1"></i>Unsaved changes
                    </span>
                    <Button
                        label="Save Schedule"
                        icon="pi pi-save"
                        severity="success"
                        :loading="savingSchedule"
                        :disabled="rows.length === 0"
                        @click="saveSchedule"
                    />
                </div>
            </div>

            <!-- Section Information -->
            <Card class="!rounded-2xl border border-slate-100 shadow-sm mb-6">
                <template #content>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-6">
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Major</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.major?.name || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Curriculum</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.curriculum?.code || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Academic Year</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.academic_year }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Year Level</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.year_level }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-slate-400 uppercase tracking-wide">Est. Students</p>
                            <p class="mt-1 text-slate-800 font-medium">{{ section.estimated_students }}</p>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Subjects / Scheduling table -->
            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3">
                        <template #start>
                            <span class="relative w-full sm:w-80">
                                <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <InputText
                                    v-model="search"
                                    placeholder="Search by code, title or category"
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
                                <Button label="Add Subject" icon="pi pi-plus" severity="success" @click="openAddDialog" />
                            </div>
                        </template>
                    </Toolbar>

                    <DataTable
                        :value="rows"
                        :loading="loading"
                        dataKey="id"
                        class="rounded-xl overflow-hidden schedule-table"
                        :rowClass="(row) => (dirtyRowIds.has(row.id) ? '!bg-amber-50' : undefined)"
                        stripedRows
                        responsiveLayout="scroll"
                        scrollable
                        scrollHeight="flex"
                        paginator
                        :rows="10"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No subjects assigned yet.</p>
                                <p class="text-slate-400 text-sm mt-1">
                                    Click "Add Subject" to load from the curriculum or select manually.
                                </p>
                                <Button
                                    label="Add Subject"
                                    icon="pi pi-plus"
                                    severity="success"
                                    class="mt-3"
                                    @click="openAddDialog"
                                />
                            </div>
                        </template>

                        <Column header="Subject Code" style="min-width: 9rem">
                            <template #body="{ data }">
                                <span class="font-medium text-slate-700">{{ data.subject?.subject_code }}</span>
                            </template>
                        </Column>
                        <Column header="Subject Title" style="min-width: 13rem">
                            <template #body="{ data }">
                                {{ data.subject?.subject_title }}
                            </template>
                        </Column>
                        <Column header="Category" style="min-width: 9rem">
                            <template #body="{ data }">
                                <Tag :value="data.subject?.category" :severity="categorySeverity(data.subject?.category)" />
                            </template>
                        </Column>
                        <Column header="Units" style="width: 5rem">
                            <template #body="{ data }">
                                {{ data.subject?.units }}
                            </template>
                        </Column>

                        <!-- Faculty -->
                        <Column header="Faculty" style="min-width: 14rem">
                            <template #body="{ data }">
                                <Select
                                    v-model="data.faculty_id"
                                    :options="facultyGroupsFor(data)"
                                    optionLabel="label"
                                    optionValue="value"
                                    optionGroupLabel="label"
                                    optionGroupChildren="items"
                                    filter
                                    showClear
                                    placeholder="Select faculty"
                                    class="w-full"
                                    :class="{ 'p-invalid': stateFor(data.id).errors.faculty_id }"
                                    emptyMessage="No active faculty"
                                    emptyFilterMessage="No matching faculty"
                                    @update:modelValue="(v) => onFacultyChange(data, v)"
                                >
                                    <template #optiongroup="{ option }">
                                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ option.label }}</span>
                                    </template>
                                </Select>
                                <p v-if="stateFor(data.id).errors.faculty_id" class="text-red-500 text-xs mt-1">
                                    <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.faculty_id }}
                                </p>
                            </template>
                        </Column>

                        <!-- Room -->
                        <Column header="Room" style="min-width: 13rem">
                            <template #body="{ data }">
                                <Select
                                    v-model="data.room_id"
                                    :options="roomGroupsFor(data)"
                                    optionLabel="label"
                                    optionValue="value"
                                    optionGroupLabel="label"
                                    optionGroupChildren="items"
                                    filter
                                    showClear
                                    placeholder="Select room"
                                    class="w-full"
                                    :class="{ 'p-invalid': stateFor(data.id).errors.room_id }"
                                    emptyMessage="No active rooms"
                                    emptyFilterMessage="No matching rooms"
                                    @update:modelValue="(v) => onRoomChange(data, v)"
                                >
                                    <template #optiongroup="{ option }">
                                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ option.label }}</span>
                                    </template>
                                </Select>
                                <p v-if="stateFor(data.id).errors.room_id" class="text-red-500 text-xs mt-1">
                                    <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.room_id }}
                                </p>
                            </template>
                        </Column>

                        <!-- Days -->
                        <Column header="Days" style="min-width: 11rem">
                            <template #body="{ data }">
                                <MultiSelect
                                    v-model="data.days"
                                    :options="dayOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    placeholder="Select days"
                                    class="w-full"
                                    :class="{ 'p-invalid': stateFor(data.id).errors.days }"
                                    @update:modelValue="(v) => onDaysChange(data, v)"
                                >
                                    <template #value="{ value, placeholder }">
                                        <span v-if="!value || value.length === 0" class="text-slate-400">{{ placeholder }}</span>
                                        <span v-else class="font-medium">{{ formatDays(value) }}</span>
                                    </template>
                                    <template #header>
                                        <div class="flex flex-wrap gap-1 px-3 pt-2 pb-1">
                                            <button
                                                v-for="preset in dayPresets"
                                                :key="preset.label"
                                                type="button"
                                                class="text-xs px-2 py-1 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-600"
                                                @click="applyDayPreset(data, preset)"
                                            >
                                                {{ preset.label }}
                                            </button>
                                        </div>
                                    </template>
                                </MultiSelect>
                                <p v-if="stateFor(data.id).errors.days" class="text-red-500 text-xs mt-1">
                                    <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.days }}
                                </p>
                            </template>
                        </Column>

                        <!-- Start Time -->
                        <Column header="Start Time" style="min-width: 9rem">
                            <template #body="{ data }">
                                <DatePicker
                                    :modelValue="startTimeModel(data)"
                                    timeOnly
                                    hourFormat="12"
                                    showIcon
                                    iconDisplay="input"
                                    placeholder="Start"
                                    class="w-full"
                                    :class="{ 'p-invalid': stateFor(data.id).errors.start_time }"
                                    @update:modelValue="(v) => onStartTimeChange(data, v)"
                                />
                                <p v-if="stateFor(data.id).errors.start_time" class="text-red-500 text-xs mt-1">
                                    <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.start_time }}
                                </p>
                            </template>
                        </Column>

                        <!-- End Time -->
                        <Column header="End Time" style="min-width: 9rem">
                            <template #body="{ data }">
                                <DatePicker
                                    :modelValue="endTimeModel(data)"
                                    timeOnly
                                    hourFormat="12"
                                    showIcon
                                    iconDisplay="input"
                                    placeholder="End"
                                    class="w-full"
                                    :class="{ 'p-invalid': stateFor(data.id).errors.end_time }"
                                    @update:modelValue="(v) => onEndTimeChange(data, v)"
                                />
                                <p v-if="stateFor(data.id).errors.end_time" class="text-red-500 text-xs mt-1">
                                    <i class="pi pi-exclamation-triangle mr-1"></i>{{ stateFor(data.id).errors.end_time }}
                                </p>
                            </template>
                        </Column>

                        <!-- Status -->
                        <Column header="Status" style="width: 9rem">
                            <template #body="{ data }">
                                <div class="flex items-center gap-1">
                                    <Tag :value="data.status" :severity="statusSeverity(data.status)" />
                                    <i
                                        v-if="hasActiveConflict(data)"
                                        class="pi pi-exclamation-triangle text-amber-500"
                                        title="Unresolved scheduling conflict"
                                    ></i>
                                </div>
                            </template>
                        </Column>

                        <Column header="Source" style="width: 9rem">
                            <template #body="{ data }">
                                <Tag :value="data.source" :severity="sourceSeverity(data.source)" />
                            </template>
                        </Column>
                        <Column header="Actions" style="width: 6rem">
                            <template #body="{ data }">
                                <Button
                                    icon="pi pi-trash"
                                    text
                                    rounded
                                    severity="danger"
                                    size="small"
                                    aria-label="Remove"
                                    @click="onRemove(data)"
                                />
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>

        <!-- Add Subject Dialog -->
        <Dialog
            v-model:visible="addDialogVisible"
            modal
            header="Add Subject"
            :style="{ width: '760px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            @hide="closeAddDialog"
        >
            <Tabs v-model:value="activeTab">
                <TabList>
                    <Tab value="curriculum">Load From Curriculum</Tab>
                    <Tab value="manual">Manual Selection</Tab>
                </TabList>
                <TabPanels>
                    <!-- Option 1: Load From Curriculum -->
                    <TabPanel value="curriculum">
                        <p class="text-sm text-slate-500 mb-3">
                            Curricula are limited to <span class="font-medium text-slate-700">{{ section.major?.name }}</span>.
                            Year Level and Semester are pre-filled from this section but can be changed.
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                            <div class="flex flex-col gap-1">
                                <label class="text-sm font-medium text-slate-700">Curriculum</label>
                                <Select
                                    v-model="curriculumForm.curriculum_id"
                                    :options="curriculums"
                                    optionLabel="code"
                                    optionValue="id"
                                    filter
                                    placeholder="Select a curriculum"
                                    class="w-full"
                                >
                                    <template #option="{ option }">
                                        <span class="font-medium">{{ option.code }}</span>
                                        <span class="text-slate-400"> — {{ option.name }}</span>
                                    </template>
                                </Select>
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm font-medium text-slate-700">Year Level</label>
                                <Select
                                    v-model="curriculumForm.year_level"
                                    :options="yearLevelOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    placeholder="Select year level"
                                    class="w-full"
                                />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm font-medium text-slate-700">Semester</label>
                                <Select
                                    v-model="curriculumForm.semester"
                                    :options="semesterOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    placeholder="Select semester"
                                    class="w-full"
                                />
                            </div>
                        </div>

                        <Button
                            label="Preview Subjects"
                            icon="pi pi-eye"
                            severity="secondary"
                            outlined
                            :loading="previewLoading"
                            @click="onPreview"
                        />

                        <small v-if="previewError" class="block text-red-500 mt-2">{{ previewError }}</small>
                        <small v-for="(err, idx) in curriculumSaveErrors" :key="idx" class="block text-red-500 mt-1">
                            {{ err }}
                        </small>

                        <!-- Preview table -->
                        <div v-if="hasPreviewed && curriculumPreviewRows.length > 0" class="mt-4">
                            <p class="text-sm font-medium text-slate-700 mb-2">
                                Preview — remove any subjects you don't want before confirming.
                            </p>
                            <div class="border border-slate-200 rounded-lg overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 text-slate-500">
                                        <tr>
                                            <th class="text-left px-3 py-2 font-medium">Code</th>
                                            <th class="text-left px-3 py-2 font-medium">Title</th>
                                            <th class="text-center px-3 py-2 font-medium">Units</th>
                                            <th class="px-3 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <tr v-for="subject in curriculumPreviewRows" :key="subject.id">
                                            <td class="px-3 py-2 font-medium text-slate-700">{{ subject.subject_code }}</td>
                                            <td class="px-3 py-2 text-slate-600">{{ subject.subject_title }}</td>
                                            <td class="px-3 py-2 text-center text-slate-600">{{ subject.units }}</td>
                                            <td class="px-3 py-2 text-right">
                                                <Button
                                                    icon="pi pi-times"
                                                    text
                                                    rounded
                                                    severity="danger"
                                                    size="small"
                                                    aria-label="Remove from preview"
                                                    @click="removePreviewRow(subject.id)"
                                                />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex justify-end mt-4">
                                <Button
                                    label="Confirm & Add"
                                    icon="pi pi-check"
                                    severity="success"
                                    :loading="curriculumSaving"
                                    @click="onConfirmCurriculumLoad"
                                />
                            </div>
                        </div>
                    </TabPanel>

                    <!-- Option 2: Manual Selection -->
                    <TabPanel value="manual">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm font-medium text-slate-700">Search Subject</label>
                            <MultiSelect
                                v-model="manualForm.subject_ids"
                                :options="manualSubjectOptions"
                                optionLabel="subject_code"
                                optionValue="id"
                                filter
                                filterPlaceholder="Search subject code or title"
                                display="chip"
                                placeholder="Select one or multiple subjects"
                                :invalid="!!manualForm.errors.subject_ids"
                                class="w-full"
                            >
                                <template #option="{ option }">
                                    <span class="font-medium">{{ option.subject_code }}</span>
                                    <span class="text-slate-400"> — {{ option.subject_title }}</span>
                                </template>
                            </MultiSelect>
                            <small v-if="manualForm.errors.subject_ids" class="text-red-500">
                                {{ manualForm.errors.subject_ids }}
                            </small>
                        </div>

                        <div class="flex justify-end mt-4">
                            <Button
                                label="Add"
                                icon="pi pi-plus"
                                severity="success"
                                :loading="manualForm.processing"
                                :disabled="manualForm.subject_ids.length === 0"
                                @click="onAddManual"
                            />
                        </div>
                    </TabPanel>
                </TabPanels>
            </Tabs>

            <template #footer>
                <Button label="Close" severity="secondary" outlined @click="closeAddDialog" />
            </template>
        </Dialog>
    </AppLayout>
</template>

<style scoped>
.schedule-table :deep(.p-select),
.schedule-table :deep(.p-multiselect),
.schedule-table :deep(.p-datepicker-input) {
    font-size: 0.85rem;
}
</style>