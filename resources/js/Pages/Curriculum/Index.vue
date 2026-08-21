<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Swal from 'sweetalert2';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import Checkbox from 'primevue/checkbox';
import FloatLabel from 'primevue/floatlabel';
import Toast from 'primevue/toast';
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    curriculums: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    activeMajors: { type: Array, default: () => [] },
    colleges: { type: Array, default: () => [] },
    curriculumYearOptions: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ curriculum_search: '' }),
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

const statusOptions = [
    { label: 'Draft', value: 'Draft' },
    { label: 'Active', value: 'Active' },
    { label: 'Archived', value: 'Archived' },
];

const statusSeverity = (status) => {
    if (status === 'Active') return 'success';
    if (status === 'Archived') return 'warn';
    return 'secondary'; // Draft
};

const majorOptions = computed(() =>
    [...props.activeMajors]
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((major) => ({ label: major.name, value: major.id })),
);

/* ------------------------------------------------------------------ */
/* Search / list                                                       */
/* ------------------------------------------------------------------ */

const search = ref(props.filters.curriculum_search ?? '');
const selectedCurriculumYear = ref(props.filters.curriculum_year || null);
const selectedCollegeId = ref(props.filters.college_id ?? null);
const selectedMajorId = ref(props.filters.major_id ?? null);
const loading = ref(false);
let searchDebounce = null;

// Curriculum Year dropdown — real (start_year, end_year) pairs that
// exist in the data (see CurriculumController::index()), never a
// hardcoded list.
const curriculumYearFilterOptions = computed(() => [
    { label: 'All Curriculum Years', value: null },
    ...props.curriculumYearOptions.map((option) => ({ label: option.label, value: option.value })),
]);

const collegeFilterOptions = computed(() => [
    { label: 'All Colleges', value: null },
    ...props.colleges.map((college) => ({ label: college.name, value: college.id })),
]);

const majorFilterOptions = computed(() => {
    const majors = selectedCollegeId.value
        ? props.activeMajors.filter((major) => major.college_id === selectedCollegeId.value)
        : props.activeMajors;

    return [
        { label: 'All Programs', value: null },
        ...[...majors].sort((a, b) => a.name.localeCompare(b.name)).map((major) => ({ label: major.name, value: major.id })),
    ];
});

// Switching the College filter narrows the Program dropdown down to
// that College's own Programs (see activeMajors' college_id above) —
// if the currently selected Program doesn't belong to the newly
// picked College, clear it first so the reload doesn't ask the
// backend to combine two contradictory filters.
const onCollegeFilterChange = () => {
    const stillValid = majorFilterOptions.value.some((option) => option.value === selectedMajorId.value);
    if (!stillValid) {
        selectedMajorId.value = null;
    }
    onFilterChange();
};

const reloadCurriculums = (extra = {}) => {
    loading.value = true;

    router.get(
        route('curriculums'),
        {
            curriculum_search: search.value,
            curriculum_year: selectedCurriculumYear.value,
            college_id: selectedCollegeId.value,
            major_id: selectedMajorId.value,
            ...extra,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['curriculums'],
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

// Dropdown picks re-query immediately (unlike the text search, which
// debounces below) and reset back to page 1.
const onFilterChange = () => {
    reloadCurriculums({ curriculum_page: 1 });
};

const activeFilterChips = computed(() => {
    const chips = [];

    if (selectedCurriculumYear.value) {
        const option = props.curriculumYearOptions.find((item) => item.value === selectedCurriculumYear.value);
        chips.push({ key: 'curriculumYear', label: option?.label ?? selectedCurriculumYear.value });
    }
    if (selectedCollegeId.value) {
        const college = props.colleges.find((item) => item.id === selectedCollegeId.value);
        chips.push({ key: 'college', label: college?.name ?? 'College' });
    }
    if (selectedMajorId.value) {
        const major = props.activeMajors.find((item) => item.id === selectedMajorId.value);
        chips.push({ key: 'major', label: major?.name ?? 'Program' });
    }
    if (search.value.trim() !== '') {
        chips.push({ key: 'search', label: `"${search.value.trim()}"` });
    }

    return chips;
});

const hasActiveFilters = computed(() => activeFilterChips.value.length > 0);

const removeFilterChip = (key) => {
    if (key === 'curriculumYear') selectedCurriculumYear.value = null;
    if (key === 'college') {
        selectedCollegeId.value = null;
        selectedMajorId.value = null;
    }
    if (key === 'major') selectedMajorId.value = null;
    if (key === 'search') search.value = '';

    reloadCurriculums({ curriculum_page: 1 });
};

// Clears every filter (search, Curriculum Year, College, Program) and
// restores the default Curriculum list.
const clearFilters = () => {
    search.value = '';
    selectedCurriculumYear.value = null;
    selectedCollegeId.value = null;
    selectedMajorId.value = null;
    reloadCurriculums({ curriculum_page: 1 });
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        reloadCurriculums({ curriculum_page: 1 });
    }, 350);
});

const onPage = (event) => {
    reloadCurriculums({ curriculum_page: event.page + 1 });
};

const onRefresh = () => {
    reloadCurriculums({ curriculum_page: props.curriculums.current_page });
};

/* ------------------------------------------------------------------ */
/* Add / Edit Curriculum dialog                                        */
/* ------------------------------------------------------------------ */

const dialogVisible = ref(false);
const dialogMode = ref('add'); // 'add' | 'edit'
const editingCurriculum = ref(null);

// Tracks whether Code / Name have been hand-edited by the user, so
// auto-generation doesn't clobber a deliberate override once they've
// started typing their own value.
const codeTouched = ref(false);
const nameTouched = ref(false);

const form = useForm({
    major_id: null,
    code: '',
    name: '',
    start_year: null,
    status: 'Draft',
    allow_new_students: true,
    description: '',
});

const selectedMajor = computed(() =>
    props.activeMajors.find((major) => major.id === form.major_id) ?? null,
);

// The Curriculum always spans 4 years from the chosen Start Year —
// this is display-only; the authoritative value is computed server-side.
const CURRICULUM_DURATION_YEARS = 4;

const endYear = computed(() => {
    if (!form.start_year) {
        return null;
    }
    return form.start_year + CURRICULUM_DURATION_YEARS;
});

const yearRangeLabel = computed(() =>
    form.start_year && endYear.value ? `${form.start_year} – ${endYear.value}` : null,
);

// Auto-generate Curriculum Code and Name from Major + Start Year.
// Example: Major "BS Information Technology" (code BSIT) + 2023
//   -> Code: BSIT-2023
//   -> Name: BS Information Technology Curriculum (2023–2027)
const applyAutoGeneration = () => {
    if (!selectedMajor.value || !form.start_year) {
        return;
    }

    if (!codeTouched.value) {
        form.code = `${selectedMajor.value.code}-${form.start_year}`;
    }

    if (!nameTouched.value) {
        form.name = `${selectedMajor.value.name} Curriculum (${form.start_year}–${endYear.value})`;
    }
};

watch(() => form.major_id, applyAutoGeneration);
watch(() => form.start_year, applyAutoGeneration);

const onCodeInput = () => {
    codeTouched.value = true;
};
const onNameInput = () => {
    nameTouched.value = true;
};

const openAdd = () => {
    dialogMode.value = 'add';
    editingCurriculum.value = null;
    codeTouched.value = false;
    nameTouched.value = false;
    form.reset();
    form.clearErrors();
    form.status = 'Draft';
    form.allow_new_students = true;
    dialogVisible.value = true;
};

const openEdit = (curriculum) => {
    dialogMode.value = 'edit';
    editingCurriculum.value = curriculum;
    // Editing an existing record always shows its saved Code/Name —
    // auto-generation shouldn't silently overwrite them.
    codeTouched.value = true;
    nameTouched.value = true;
    form.clearErrors();
    form.major_id = curriculum.major_id;
    form.code = curriculum.code;
    form.name = curriculum.name;
    form.start_year = curriculum.start_year;
    form.status = curriculum.status;
    form.allow_new_students = !!curriculum.allow_new_students;
    form.description = curriculum.description ?? '';
    dialogVisible.value = true;
};

const closeDialog = () => {
    dialogVisible.value = false;
    form.reset();
    form.clearErrors();
    editingCurriculum.value = null;
};

const onSave = () => {
    const onError = () => {
        toast.add({
            severity: 'warn',
            summary: 'Missing information',
            detail: 'Please check the highlighted fields and try again.',
            life: 3000,
        });
    };

    if (dialogMode.value === 'add') {
        form.post(route('curriculums.store'), {
            preserveScroll: true,
            onSuccess: () => closeDialog(),
            onError,
        });
    } else {
        form.put(route('curriculums.update', editingCurriculum.value.id), {
            preserveScroll: true,
            onSuccess: () => closeDialog(),
            onError,
        });
    }
};

const onDelete = (curriculum) => {
    Swal.fire({
        title: 'Are you sure you want to delete this curriculum?',
        text: curriculum.name,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('curriculums.destroy', curriculum.id), {
                preserveScroll: true,
                preserveState: true,
            });
        }
    });
};

const onRestore = (curriculum) => {
    router.put(route('curriculums.restore', curriculum.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
};
</script>

<template>
    <Head title="Curriculum" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Curriculum</span>
        </template>

        <div class="max-w-7xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Page Title -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    Curriculum
                    <InfoPopover
                        title="Curriculum"
                        :paragraphs="[
                            'A Curriculum is the academic plan for a Major — which Subjects are offered, in which year and semester, for a given school-year range.',
                        ]"
                        :bullets="[
                            'Sections pull their required Subjects from the Curriculum assigned to their Major and year level.',
                            'A Major can have more than one Curriculum version across different school years.',
                            'Removing a Subject from a Curriculum does not remove it from Sections already scheduled under an older version.',
                        ]"
                    />
                </h1>
                <p class="mt-1" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                    Manage the academic plans (subjects offered per Major) used across school years.
                </p>
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
                                    placeholder="Search by code, name, major or start/end year"
                                    class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                    :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                />
                            </span>
                            <Select
                                v-model="selectedCollegeId"
                                :options="collegeFilterOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="All Colleges"
                                class="w-full sm:w-52"
                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                @change="onCollegeFilterChange"
                            />
                            <Select
                                v-model="selectedMajorId"
                                :options="majorFilterOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="All Programs"
                                filter
                                class="w-full sm:w-56"
                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                @change="onFilterChange"
                            />
                            <Select
                                v-model="selectedCurriculumYear"
                                :options="curriculumYearFilterOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="All Curriculum Years"
                                class="w-full sm:w-52"
                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                @change="onFilterChange"
                            />
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
                                <Button label="Add Curriculum" icon="pi pi-plus" @click="openAdd" />
                            </div>
                        </template>
                    </Toolbar>

                    <!-- Active Filter Indicator -->
                    <div v-if="hasActiveFilters" class="flex flex-wrap items-center gap-2 pb-4 -mt-2">
                        <span class="text-xs font-medium text-slate-500">Filters ({{ activeFilterChips.length }})</span>
                        <Tag
                            v-for="chip in activeFilterChips"
                            :key="chip.key"
                            severity="secondary"
                            class="!cursor-pointer"
                            @click="removeFilterChip(chip.key)"
                        >
                            <span class="flex items-center gap-1">
                                {{ chip.label }}
                                <i class="pi pi-times text-[10px]"></i>
                            </span>
                        </Tag>
                        <Button
                            label="Clear Filters"
                            size="small"
                            text
                            severity="secondary"
                            class="!py-1 !px-2 !text-xs"
                            @click="clearFilters"
                        />
                    </div>

                    <!-- Curriculums Table -->
                    <DataTable
                        :value="curriculums.data"
                        :loading="loading"
                        dataKey="id"
                        class="neu-inset neu-table rounded-xl overflow-hidden"
                        :class="isDark ? 'neu-table-dark' : ''"
                        stripedRows
                        responsiveLayout="scroll"
                        lazy
                        paginator
                        :rows="curriculums.per_page"
                        :totalRecords="curriculums.total"
                        :first="(curriculums.current_page - 1) * curriculums.per_page"
                        @page="onPage"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No curriculums found.</p>
                                <p v-if="hasActiveFilters" class="text-slate-400 text-sm mt-1">
                                    Try changing or clearing your filters.
                                </p>
                                <Button
                                    v-if="hasActiveFilters"
                                    label="Clear Filters"
                                    icon="pi pi-filter-slash"
                                    severity="secondary"
                                    outlined
                                    class="mt-3"
                                    @click="clearFilters"
                                />
                                <Button
                                    v-else
                                    label="Add Curriculum"
                                    icon="pi pi-plus"
                                    class="mt-3"
                                    @click="openAdd"
                                />
                            </div>
                        </template>

                        <Column field="code" header="Code" style="width: 10rem" />
                        <Column field="name" header="Curriculum" />
                        <Column header="Major" style="width: 16rem">
                            <template #body="{ data }">
                                {{ data.major?.name || '—' }}
                            </template>
                        </Column>
                        <Column header="Duration" style="width: 10rem">
                            <template #body="{ data }">
                                {{ data.start_year }} - {{ data.end_year }}
                            </template>
                        </Column>
                        <Column header="Status" style="width: 9rem">
                            <template #body="{ data }">
                                <Tag v-if="data.deleted_at" value="Deleted" severity="danger" />
                                <Tag v-else :value="data.status" :severity="statusSeverity(data.status)" />
                            </template>
                        </Column>
                        <Column header="Allow New Students" style="width: 10rem">
                            <template #body="{ data }">
                                <Tag
                                    :value="data.allow_new_students ? 'Yes' : 'No'"
                                    :severity="data.allow_new_students ? 'success' : 'secondary'"
                                />
                            </template>
                        </Column>
                        <Column header="Actions" style="width: 16rem">
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <template v-if="!data.deleted_at">
                                        <Button
                                            icon="pi pi-list"
                                            label="Manage Subjects"
                                            text
                                            severity="info"
                                            size="small"
                                            @click="router.visit(route('curriculums.subjects', data.id))"
                                        />
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
                                            @click="onDelete(data)"
                                        />
                                    </template>
                                    <Button
                                        v-else
                                        icon="pi pi-refresh"
                                        text
                                        rounded
                                        severity="success"
                                        size="small"
                                        label="Restore"
                                        @click="onRestore(data)"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
            </div>
        </div>

        <!-- Add / Edit Curriculum Modal -->
        <Dialog
            v-model:visible="dialogVisible"
            modal
            :style="{ width: '700px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
        >
            <template #header>
                <span class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    {{ dialogMode === 'add' ? 'Add Curriculum' : 'Edit Curriculum' }}
                </span>
            </template>

            <form class="pt-2 neu-form" autocomplete="off" @submit.prevent="onSave">
                <!-- Major -->
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="curriculumMajor"
                            v-model="form.major_id"
                            :options="majorOptions"
                            optionLabel="label"
                            optionValue="value"
                            filter
                            class="w-full"
                            :invalid="!!form.errors.major_id"
                        />
                        <label for="curriculumMajor">Major *</label>
                    </FloatLabel>
                    <small v-if="form.errors.major_id" class="text-red-500 -mt-4">{{ form.errors.major_id }}</small>
                </div>

                <!-- Start Year -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputNumber
                            id="curriculumStartYear"
                            v-model="form.start_year"
                            class="w-full"
                            :useGrouping="false"
                            :min="2000"
                            :max="2100"
                            :invalid="!!form.errors.start_year"
                        />
                        <label for="curriculumStartYear">Start Year *</label>
                    </FloatLabel>

                    <div
                        v-if="yearRangeLabel"
                        class="flex flex-col justify-center px-3 py-1 rounded-lg border"
                        :class="isDark ? 'bg-emerald-500/10 border-emerald-400/20' : 'bg-emerald-50 border-emerald-100'"
                    >
                        <span class="text-[11px] font-medium uppercase tracking-wide" :class="isDark ? 'text-emerald-400' : 'text-emerald-600'">Curriculum Duration</span>
                        <span class="text-sm font-semibold" :class="isDark ? 'text-emerald-300' : 'text-emerald-700'">{{ yearRangeLabel }}</span>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-1 mt-1">
                    <small v-if="form.errors.start_year" class="text-red-500">{{ form.errors.start_year }}</small>
                    <small v-if="form.errors.end_year" class="text-red-500">{{ form.errors.end_year }}</small>
                </div>

                <!-- Curriculum Code / Curriculum Name (auto-generated, editable) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="curriculumCode"
                            v-model="form.code"
                            class="w-full"
                            maxlength="50"
                            autocomplete="off"
                            :invalid="!!form.errors.code"
                            @input="onCodeInput"
                        />
                        <label for="curriculumCode">Curriculum Code *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Select
                            id="curriculumStatus"
                            v-model="form.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!form.errors.status"
                        />
                        <label for="curriculumStatus">Status *</label>
                    </FloatLabel>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Code and Name are auto-generated from Major + Start Year — feel free to change them.
                </p>
                <small v-if="form.errors.code" class="text-red-500 block">{{ form.errors.code }}</small>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            v-uppercase
                            id="curriculumName"
                            v-model="form.name"
                            class="w-full"
                            maxlength="255"
                            autocomplete="off"
                            :invalid="!!form.errors.name"
                            @input="onNameInput"
                        />
                        <label for="curriculumName">Curriculum Name *</label>
                    </FloatLabel>
                    <small v-if="form.errors.name" class="text-red-500 -mt-4">{{ form.errors.name }}</small>
                </div>

                <!-- Allow New Students -->
                <div class="flex items-center gap-2 mt-5">
                    <Checkbox
                        v-model="form.allow_new_students"
                        inputId="curriculumAllowNewStudents"
                        binary
                    />
                    <label for="curriculumAllowNewStudents" class="text-sm" :class="isDark ? 'text-slate-200' : 'text-[#1E293B]'">
                        Allow New Students
                    </label>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    When disabled, only existing or irregular students may continue using this curriculum.
                </p>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Textarea
                            id="curriculumDescription"
                            v-model="form.description"
                            class="w-full"
                            rows="3"
                            autoResize
                            :invalid="!!form.errors.description"
                        />
                        <label for="curriculumDescription">Description</label>
                    </FloatLabel>
                    <small v-if="form.errors.description" class="text-red-500 -mt-4">{{ form.errors.description }}</small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="form.processing" @click="closeDialog" />
                <Button label="Save" icon="pi pi-check" :loading="form.processing" @click="onSave" />
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