<script setup>
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
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
    semesterOptions: { type: Array, default: () => [] },
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
/* Add Subject dialog                                                  */
/* ------------------------------------------------------------------ */

const addDialogVisible = ref(false);
const activeTab = ref('curriculum');

const openAddDialog = () => {
    activeTab.value = 'curriculum';
    curriculumForm.curriculum_id = props.curriculums.length === 1 ? props.curriculums[0].id : null;
    curriculumForm.year_level = props.sectionYearLevel;
    curriculumForm.semester = null;
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

        <div class="max-w-7xl mx-auto w-full">
            <!-- Back link -->
            <div class="mb-4">
                <Link :href="route('scheduling.sections')" class="text-sm text-slate-500 hover:text-slate-700">
                    <i class="pi pi-arrow-left mr-1"></i> Back to Sections
                </Link>
            </div>

            <!-- Page Title -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">
                    {{ section.section_code }} — {{ section.section_name }}
                </h1>
                <p class="mt-1 text-slate-500">
                    Build the list of subjects this section needs scheduled.
                </p>
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

            <!-- Subjects -->
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
                        :value="sectionSubjects"
                        :loading="loading"
                        dataKey="id"
                        class="rounded-xl overflow-hidden"
                        stripedRows
                        responsiveLayout="scroll"
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

                        <Column header="Subject Code" style="width: 10rem">
                            <template #body="{ data }">
                                {{ data.subject?.subject_code }}
                            </template>
                        </Column>
                        <Column header="Subject Title">
                            <template #body="{ data }">
                                {{ data.subject?.subject_title }}
                            </template>
                        </Column>
                        <Column header="Category" style="width: 10rem">
                            <template #body="{ data }">
                                <Tag :value="data.subject?.category" :severity="categorySeverity(data.subject?.category)" />
                            </template>
                        </Column>
                        <Column header="Units" style="width: 6rem">
                            <template #body="{ data }">
                                {{ data.subject?.units }}
                            </template>
                        </Column>
                        <Column header="Lecture Hrs" style="width: 7rem">
                            <template #body="{ data }">
                                {{ data.subject?.lecture_hours }}
                            </template>
                        </Column>
                        <Column header="Lab Hrs" style="width: 7rem">
                            <template #body="{ data }">
                                {{ data.subject?.laboratory_hours }}
                            </template>
                        </Column>
                        <Column header="Source" style="width: 9rem">
                            <template #body="{ data }">
                                <Tag :value="data.source" :severity="sourceSeverity(data.source)" />
                            </template>
                        </Column>
                        <Column header="Actions" style="width: 7rem">
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