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
    sections: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    filters: {
        type: Object,
        default: () => ({ section_search: '' }),
    },
    activeMajors: { type: Array, default: () => [] },
    curriculums: { type: Array, default: () => [] },
    yearLevels: { type: Array, default: () => [] },
    semesterOptions: { type: Array, default: () => [] },
    academicYears: { type: Array, default: () => [] },
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

const search = ref(props.filters.section_search ?? '');
const loading = ref(false);
let searchDebounce = null;

const reloadSections = (extra = {}) => {
    loading.value = true;

    router.get(
        route('scheduling.sections'),
        { section_search: search.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['sections'],
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

/**
 * Clicking a Section row opens the Edit Section workspace (Section
 * Information / Subjects / Schedule tabs) for that Section. Edit/Delete
 * buttons in the Actions column call @click.stop so they don't also
 * trigger this navigation.
 */
const goToSectionSubjects = (section) => {
    router.get(route('scheduling.section-subjects.show', section.id));
};

/**
 * "Manage Subjects" (book icon) opens the dedicated Subject Assignment
 * workspace at /scheduling/sections/{section}/subjects — a full page,
 * not a modal.
 */
const goToManageSubjects = (section) => {
    router.get(route('scheduling.sections.subjects', section.id));
};

const onRowClick = (event) => {
    goToSectionSubjects(event.data);
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        reloadSections({ section_page: 1 });
    }, 350);
});

const onPage = (event) => {
    reloadSections({ section_page: event.page + 1 });
};

const onRefresh = () => {
    reloadSections({ section_page: props.sections.current_page });
};

/* ------------------------------------------------------------------ */
/* Add / Edit Section                                                  */
/* ------------------------------------------------------------------ */

const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

const yearLevelOptions = computed(() => props.yearLevels.map((level) => ({ label: level, value: level })));
const semesterSelectOptions = computed(() => props.semesterOptions.map((sem) => ({ label: sem, value: sem })));
const academicYearOptions = computed(() => props.academicYears.map((year) => ({ label: year, value: year })));

// "Add Section" only — editing now happens on the full Edit Section
// workspace page (see openEdit below), which is the single place
// scheduling for a section is managed.
const addSectionVisible = ref(false);
const editingSection = ref(null);

const sectionForm = useForm({
    section_code: '',
    section_name: '',
    major_id: null,
    curriculum_id: null,
    year_level: null,
    academic_year: null,
    semester: null,
    estimated_students: 1,
    status: 'Active',
    remarks: '',
});

// Only show curriculums that belong to the selected Major.
const filteredCurriculums = computed(() => {
    if (!sectionForm.major_id) {
        return [];
    }

    return props.curriculums
        .filter((curriculum) => curriculum.major_id === sectionForm.major_id)
        .map((curriculum) => ({ label: `${curriculum.code} — ${curriculum.name}`, value: curriculum.id }));
});

// If the Major changes and the currently selected Curriculum no longer
// belongs to it, clear the Curriculum selection.
watch(
    () => sectionForm.major_id,
    () => {
        const stillValid = filteredCurriculums.value.some(
            (curriculum) => curriculum.value === sectionForm.curriculum_id,
        );
        if (!stillValid) {
            sectionForm.curriculum_id = null;
        }
    },
);

const openAdd = () => {
    editingSection.value = null;
    sectionForm.reset();
    sectionForm.clearErrors();
    addSectionVisible.value = true;
};

// Edit now navigates straight into the Edit Section workspace (same
// page reached by clicking the row) instead of opening a dialog here.
const openEdit = (section) => {
    goToSectionSubjects(section);
};

const closeAddSection = () => {
    addSectionVisible.value = false;
    editingSection.value = null;
    sectionForm.reset();
    sectionForm.clearErrors();
};

const onSaveSection = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            const wasEditing = !!editingSection.value;
            closeAddSection();
            Swal.fire({
                title: wasEditing ? 'Section updated' : 'Section saved',
                text: wasEditing
                    ? 'The section was updated successfully.'
                    : 'The section was created successfully.',
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

    if (editingSection.value) {
        sectionForm.put(route('scheduling.sections.update', editingSection.value.id), options);
    } else {
        sectionForm.post(route('scheduling.sections.store'), options);
    }
};

const onDeleteSection = (section) => {
    Swal.fire({
        title: 'Delete this section?',
        text: `${section.section_code} — ${section.section_name} will be permanently deleted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('scheduling.sections.destroy', section.id), {
                preserveScroll: true,
                onSuccess: () => onRefresh(),
            });
        }
    });
};
</script>

<template>
    <Head title="Sections" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Sections</span>
        </template>

        <div class="max-w-7xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Page Title -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">Sections</h1>
                <p class="mt-1 text-slate-500">
                    Manage academic sections used for class scheduling.
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
                                    placeholder="Search by code, name, major or year"
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
                                <Button label="Add Section" icon="pi pi-plus" severity="success" @click="openAdd" />
                            </div>
                        </template>
                    </Toolbar>

                    <!-- Sections Table -->
                    <DataTable
                        :value="sections.data"
                        :loading="loading"
                        dataKey="id"
                        class="rounded-xl overflow-hidden"
                        stripedRows
                        responsiveLayout="scroll"
                        lazy
                        paginator
                        :rows="sections.per_page"
                        :totalRecords="sections.total"
                        :first="(sections.current_page - 1) * sections.per_page"
                        rowHover
                        :rowClass="() => 'cursor-pointer'"
                        @page="onPage"
                        @row-click="onRowClick"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No sections found.</p>
                                <p class="text-slate-400 text-sm mt-1">
                                    Click "Add Section" to create your first section.
                                </p>
                                <Button
                                    label="Add Section"
                                    icon="pi pi-plus"
                                    severity="success"
                                    class="mt-3"
                                    @click="openAdd"
                                />
                            </div>
                        </template>

                        <Column field="section_code" header="Section Code" style="width: 10rem" />
                        <Column field="section_name" header="Section Name" style="width: 10rem" />
                        <Column header="Major" style="width: 10rem">
                            <template #body="{ data }">
                                {{ data.major?.name || '—' }}
                            </template>
                        </Column>
                        <Column header="Curriculum" style="width: 12rem">
                            <template #body="{ data }">
                                {{ data.curriculum?.code || '—' }}
                            </template>
                        </Column>
                        <Column header="Year Level" style="width: 9rem">
                            <template #body="{ data }">
                                {{ data.year_level }}
                            </template>
                        </Column>
                        <Column header="Academic Year" style="width: 9rem">
                            <template #body="{ data }">
                                {{ data.academic_year }}
                            </template>
                        </Column>
                        <Column header="Est. Students" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.estimated_students }}
                            </template>
                        </Column>
                        <Column header="Status" style="width: 9rem">
                            <template #body="{ data }">
                                <Tag
                                    :value="data.status"
                                    :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                />
                            </template>
                        </Column>
                        <Column header="Actions" style="width: 12rem">
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <Button
                                        icon="pi pi-book"
                                        text
                                        rounded
                                        severity="info"
                                        size="small"
                                        aria-label="Manage Subjects"
                                        @click.stop="goToManageSubjects(data)"
                                    />
                                    <Button
                                        icon="pi pi-pencil"
                                        text
                                        rounded
                                        severity="secondary"
                                        size="small"
                                        aria-label="Edit"
                                        @click.stop="openEdit(data)"
                                    />
                                    <Button
                                        icon="pi pi-trash"
                                        text
                                        rounded
                                        severity="danger"
                                        size="small"
                                        aria-label="Delete"
                                        @click.stop="onDeleteSection(data)"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>

        <!-- Add Section Dialog -->
        <Dialog
            v-model:visible="addSectionVisible"
            modal
            :header="editingSection ? 'Edit Section' : 'Add Section'"
            :style="{ width: '700px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{ root: { class: isDark ? 'dark-scope' : '' } }"
            @hide="closeAddSection"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4" @submit.prevent="onSaveSection">
                <!-- Section Code -->
                <div class="flex flex-col gap-1">
                    <label for="section_code" class="text-sm font-medium text-slate-700">
                        Section Code <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="section_code"
                        v-model="sectionForm.section_code"
                        placeholder="e.g. BSIT-1A, BSIT-2B"
                        :invalid="!!sectionForm.errors.section_code"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.section_code" class="text-red-500">
                        {{ sectionForm.errors.section_code }}
                    </small>
                </div>

                <!-- Section Name -->
                <div class="flex flex-col gap-1">
                    <label for="section_name" class="text-sm font-medium text-slate-700">
                        Section Name <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="section_name"
                        v-model="sectionForm.section_name"
                        placeholder="e.g. Section A"
                        :invalid="!!sectionForm.errors.section_name"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.section_name" class="text-red-500">
                        {{ sectionForm.errors.section_name }}
                    </small>
                </div>

                <!-- Major -->
                <div class="flex flex-col gap-1">
                    <label for="major_id" class="text-sm font-medium text-slate-700">
                        Major <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="major_id"
                        v-model="sectionForm.major_id"
                        :options="activeMajors"
                        optionLabel="name"
                        optionValue="id"
                        filter
                        placeholder="Select a major"
                        :invalid="!!sectionForm.errors.major_id"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.major_id" class="text-red-500">
                        {{ sectionForm.errors.major_id }}
                    </small>
                </div>

                <!-- Curriculum -->
                <div class="flex flex-col gap-1">
                    <label for="curriculum_id" class="text-sm font-medium text-slate-700">
                        Curriculum <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="curriculum_id"
                        v-model="sectionForm.curriculum_id"
                        :options="filteredCurriculums"
                        optionLabel="label"
                        optionValue="value"
                        filter
                        :disabled="!sectionForm.major_id"
                        :placeholder="sectionForm.major_id ? 'Select a curriculum' : 'Select a major first'"
                        :invalid="!!sectionForm.errors.curriculum_id"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.curriculum_id" class="text-red-500">
                        {{ sectionForm.errors.curriculum_id }}
                    </small>
                </div>

                <!-- Year Level -->
                <div class="flex flex-col gap-1">
                    <label for="year_level" class="text-sm font-medium text-slate-700">
                        Year Level <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="year_level"
                        v-model="sectionForm.year_level"
                        :options="yearLevelOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select year level"
                        :invalid="!!sectionForm.errors.year_level"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.year_level" class="text-red-500">
                        {{ sectionForm.errors.year_level }}
                    </small>
                </div>

                <!-- Academic Year -->
                <div class="flex flex-col gap-1">
                    <label for="academic_year" class="text-sm font-medium text-slate-700">
                        Academic Year <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="academic_year"
                        v-model="sectionForm.academic_year"
                        :options="academicYearOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select academic year"
                        :invalid="!!sectionForm.errors.academic_year"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.academic_year" class="text-red-500">
                        {{ sectionForm.errors.academic_year }}
                    </small>
                </div>

                <!-- Semester -->
                <div class="flex flex-col gap-1">
                    <label for="semester" class="text-sm font-medium text-slate-700">
                        Semester <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="semester"
                        v-model="sectionForm.semester"
                        :options="semesterSelectOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select semester"
                        :invalid="!!sectionForm.errors.semester"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.semester" class="text-red-500">
                        {{ sectionForm.errors.semester }}
                    </small>
                </div>

                <!-- Estimated Students -->
                <div class="flex flex-col gap-1">
                    <label for="estimated_students" class="text-sm font-medium text-slate-700">
                        Estimated Number of Students <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="estimated_students"
                        v-model="sectionForm.estimated_students"
                        :min="1"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!sectionForm.errors.estimated_students"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="sectionForm.errors.estimated_students" class="text-red-500">
                        {{ sectionForm.errors.estimated_students }}
                    </small>
                </div>

                <!-- Status -->
                <div class="flex flex-col gap-1">
                    <label for="status" class="text-sm font-medium text-slate-700">
                        Status <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="status"
                        v-model="sectionForm.status"
                        :options="statusOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select status"
                        :invalid="!!sectionForm.errors.status"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.status" class="text-red-500">
                        {{ sectionForm.errors.status }}
                    </small>
                </div>

                <!-- Remarks -->
                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label for="remarks" class="text-sm font-medium text-slate-700">Remarks</label>
                    <Textarea
                        id="remarks"
                        v-model="sectionForm.remarks"
                        autoResize
                        rows="3"
                        placeholder="Optional notes about this section"
                        :invalid="!!sectionForm.errors.remarks"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.remarks" class="text-red-500">
                        {{ sectionForm.errors.remarks }}
                    </small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeAddSection" />
                <Button
                    :label="editingSection ? 'Update Section' : 'Save Section'"
                    icon="pi pi-check"
                    severity="success"
                    :loading="sectionForm.processing"
                    @click="onSaveSection"
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