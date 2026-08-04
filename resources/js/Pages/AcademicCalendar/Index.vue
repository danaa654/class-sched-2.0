<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Swal from 'sweetalert2';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import FloatLabel from 'primevue/floatlabel';
import Toast from 'primevue/toast';

const props = defineProps({
    schoolYears: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    semesters: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    academicTerms: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    activeSchoolYears: { type: Array, default: () => [] },
    activeSemesters: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ school_year_search: '', semester_search: '', academic_term_search: '' }),
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
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

/* ------------------------------------------------------------------ */
/* School Years tab                                                    */
/* ------------------------------------------------------------------ */

const schoolYearSearch = ref(props.filters.school_year_search ?? '');
const schoolYearLoading = ref(false);
let schoolYearSearchDebounce = null;

const reloadSchoolYears = (extra = {}) => {
    schoolYearLoading.value = true;

    router.get(
        route('academic-calendar'),
        { school_year_search: schoolYearSearch.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['schoolYears'],
            onFinish: () => {
                schoolYearLoading.value = false;
            },
        },
    );
};

watch(schoolYearSearch, () => {
    clearTimeout(schoolYearSearchDebounce);
    schoolYearSearchDebounce = setTimeout(() => {
        reloadSchoolYears({ school_year_page: 1 });
    }, 350);
});

const onSchoolYearPage = (event) => {
    reloadSchoolYears({ school_year_page: event.page + 1 });
};

const onRefreshSchoolYears = () => {
    reloadSchoolYears({ school_year_page: props.schoolYears.current_page });
};

const schoolYearDialogVisible = ref(false);
const schoolYearDialogMode = ref('add'); // 'add' | 'edit'
const editingSchoolYear = ref(null);

const schoolYearForm = useForm({
    start_year: null,
    end_year: null,
    status: 'Active',
});

// Read-only preview of the auto-generated name (e.g. 2025-2026). The user
// never types this directly — it's derived from Start Year/End Year.
const schoolYearNamePreview = computed(() => {
    if (!schoolYearForm.start_year || !schoolYearForm.end_year) {
        return '—';
    }

    return `${schoolYearForm.start_year}-${schoolYearForm.end_year}`;
});

// Keep End Year one year ahead of Start Year automatically, so the common
// case needs no extra typing (the user can still override End Year).
watch(
    () => schoolYearForm.start_year,
    (newStartYear, oldStartYear) => {
        if (newStartYear === oldStartYear) {
            return;
        }

        if (newStartYear && (!schoolYearForm.end_year || schoolYearForm.end_year === oldStartYear + 1)) {
            schoolYearForm.end_year = newStartYear + 1;
        }
    },
);

const openAddSchoolYear = () => {
    schoolYearDialogMode.value = 'add';
    editingSchoolYear.value = null;
    schoolYearForm.reset();
    schoolYearForm.clearErrors();
    schoolYearForm.status = 'Active';
    schoolYearDialogVisible.value = true;
};

const openEditSchoolYear = (schoolYear) => {
    schoolYearDialogMode.value = 'edit';
    editingSchoolYear.value = schoolYear;
    schoolYearForm.clearErrors();
    schoolYearForm.start_year = schoolYear.start_year;
    schoolYearForm.end_year = schoolYear.end_year;
    schoolYearForm.status = schoolYear.status;
    schoolYearDialogVisible.value = true;
};

const closeSchoolYearDialog = () => {
    schoolYearDialogVisible.value = false;
    schoolYearForm.reset();
    schoolYearForm.clearErrors();
    editingSchoolYear.value = null;
};

const onSaveSchoolYear = () => {
    const onError = () => {
        toast.add({
            severity: 'warn',
            summary: 'Missing information',
            detail: 'Please check the highlighted fields and try again.',
            life: 3000,
        });
    };

    if (schoolYearDialogMode.value === 'add') {
        schoolYearForm.post(route('school-years.store'), {
            preserveScroll: true,
            onSuccess: () => closeSchoolYearDialog(),
            onError,
        });
    } else {
        schoolYearForm.put(route('school-years.update', editingSchoolYear.value.id), {
            preserveScroll: true,
            onSuccess: () => closeSchoolYearDialog(),
            onError,
        });
    }
};

const onDeleteSchoolYear = (schoolYear) => {
    Swal.fire({
        title: 'Are you sure you want to delete this school year?',
        text: schoolYear.name,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('school-years.destroy', schoolYear.id), {
                preserveScroll: true,
                preserveState: true,
            });
        }
    });
};

const onRestoreSchoolYear = (schoolYear) => {
    router.put(route('school-years.restore', schoolYear.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
};

/* ------------------------------------------------------------------ */
/* Semesters tab                                                       */
/* ------------------------------------------------------------------ */

const semesterSearch = ref(props.filters.semester_search ?? '');
const semesterLoading = ref(false);
let semesterSearchDebounce = null;

const reloadSemesters = (extra = {}) => {
    semesterLoading.value = true;

    router.get(
        route('academic-calendar'),
        { semester_search: semesterSearch.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['semesters'],
            onFinish: () => {
                semesterLoading.value = false;
            },
        },
    );
};

watch(semesterSearch, () => {
    clearTimeout(semesterSearchDebounce);
    semesterSearchDebounce = setTimeout(() => {
        reloadSemesters({ semester_page: 1 });
    }, 350);
});

const onSemesterPage = (event) => {
    reloadSemesters({ semester_page: event.page + 1 });
};

const onRefreshSemesters = () => {
    reloadSemesters({ semester_page: props.semesters.current_page });
};

const semesterDialogVisible = ref(false);
const semesterDialogMode = ref('add'); // 'add' | 'edit'
const editingSemester = ref(null);

const semesterForm = useForm({
    name: '',
    short_name: '',
    display_order: null,
    status: 'Active',
});

const openAddSemester = () => {
    semesterDialogMode.value = 'add';
    editingSemester.value = null;
    semesterForm.reset();
    semesterForm.clearErrors();
    semesterForm.status = 'Active';
    semesterDialogVisible.value = true;
};

const openEditSemester = (semester) => {
    semesterDialogMode.value = 'edit';
    editingSemester.value = semester;
    semesterForm.clearErrors();
    semesterForm.name = semester.name;
    semesterForm.short_name = semester.short_name;
    semesterForm.display_order = semester.display_order;
    semesterForm.status = semester.status;
    semesterDialogVisible.value = true;
};

const closeSemesterDialog = () => {
    semesterDialogVisible.value = false;
    semesterForm.reset();
    semesterForm.clearErrors();
    editingSemester.value = null;
};

const onSaveSemester = () => {
    const onError = () => {
        toast.add({
            severity: 'warn',
            summary: 'Missing information',
            detail: 'Please check the highlighted fields and try again.',
            life: 3000,
        });
    };

    if (semesterDialogMode.value === 'add') {
        semesterForm.post(route('semesters.store'), {
            preserveScroll: true,
            onSuccess: () => closeSemesterDialog(),
            onError,
        });
    } else {
        semesterForm.put(route('semesters.update', editingSemester.value.id), {
            preserveScroll: true,
            onSuccess: () => closeSemesterDialog(),
            onError,
        });
    }
};

const onDeleteSemester = (semester) => {
    Swal.fire({
        title: 'Are you sure you want to delete this semester?',
        text: semester.name,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('semesters.destroy', semester.id), {
                preserveScroll: true,
                preserveState: true,
            });
        }
    });
};

const onRestoreSemester = (semester) => {
    router.put(route('semesters.restore', semester.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
};

/* ------------------------------------------------------------------ */
/* Academic Terms tab                                                  */
/* ------------------------------------------------------------------ */

const academicTermSearch = ref(props.filters.academic_term_search ?? '');
const academicTermLoading = ref(false);
let academicTermSearchDebounce = null;

const reloadAcademicTerms = (extra = {}) => {
    academicTermLoading.value = true;

    router.get(
        route('academic-calendar'),
        { academic_term_search: academicTermSearch.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['academicTerms'],
            onFinish: () => {
                academicTermLoading.value = false;
            },
        },
    );
};

watch(academicTermSearch, () => {
    clearTimeout(academicTermSearchDebounce);
    academicTermSearchDebounce = setTimeout(() => {
        reloadAcademicTerms({ academic_term_page: 1 });
    }, 350);
});

const onAcademicTermPage = (event) => {
    reloadAcademicTerms({ academic_term_page: event.page + 1 });
};

const onRefreshAcademicTerms = () => {
    reloadAcademicTerms({ academic_term_page: props.academicTerms.current_page });
};

const academicTermDialogVisible = ref(false);
const academicTermDialogMode = ref('add'); // 'add' | 'edit'
const editingAcademicTerm = ref(null);

const academicTermForm = useForm({
    school_year_id: null,
    semester_id: null,
    status: 'Active',
    remarks: '',
});

const openAddAcademicTerm = () => {
    academicTermDialogMode.value = 'add';
    editingAcademicTerm.value = null;
    academicTermForm.reset();
    academicTermForm.clearErrors();
    academicTermForm.status = 'Active';
    academicTermDialogVisible.value = true;
};

const openEditAcademicTerm = (academicTerm) => {
    academicTermDialogMode.value = 'edit';
    editingAcademicTerm.value = academicTerm;
    academicTermForm.clearErrors();
    academicTermForm.school_year_id = academicTerm.school_year_id;
    academicTermForm.semester_id = academicTerm.semester_id;
    academicTermForm.status = academicTerm.status;
    academicTermForm.remarks = academicTerm.remarks ?? '';
    academicTermDialogVisible.value = true;
};

const closeAcademicTermDialog = () => {
    academicTermDialogVisible.value = false;
    academicTermForm.reset();
    academicTermForm.clearErrors();
    editingAcademicTerm.value = null;
};

const onSaveAcademicTerm = () => {
    const onError = () => {
        toast.add({
            severity: 'warn',
            summary: 'Missing information',
            detail: 'Please check the highlighted fields and try again.',
            life: 3000,
        });
    };

    if (academicTermDialogMode.value === 'add') {
        academicTermForm.post(route('academic-terms.store'), {
            preserveScroll: true,
            onSuccess: () => closeAcademicTermDialog(),
            onError,
        });
    } else {
        academicTermForm.put(route('academic-terms.update', editingAcademicTerm.value.id), {
            preserveScroll: true,
            onSuccess: () => closeAcademicTermDialog(),
            onError,
        });
    }
};

const onDeleteAcademicTerm = (academicTerm) => {
    Swal.fire({
        title: 'Are you sure you want to delete this academic term?',
        text: `${academicTerm.school_year?.name ?? ''} - ${academicTerm.semester?.name ?? ''}`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('academic-terms.destroy', academicTerm.id), {
                preserveScroll: true,
                preserveState: true,
            });
        }
    });
};

const onRestoreAcademicTerm = (academicTerm) => {
    router.put(route('academic-terms.restore', academicTerm.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
};
</script>

<template>
    <Head title="Academic Calendar" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Academic Calendar</span>
        </template>

        <div class="max-w-7xl mx-auto w-full">
            <!-- Page Title -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">Academic Calendar</h1>
                <p class="mt-1 text-slate-500">
                    Manage school years, semesters, and academic terms.
                </p>
            </div>

            <!-- Tabs -->
            <Tabs value="school-years">
                <TabList>
                    <Tab value="school-years">School Years</Tab>
                    <Tab value="semesters">Semesters</Tab>
                    <Tab value="academic-terms">Academic Terms</Tab>
                </TabList>

                <TabPanels>
                    <!-- School Years -->
                    <TabPanel value="school-years">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm mt-4">
                            <template #content>
                                <!-- Top Toolbar -->
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3">
                                    <template #start>
                                        <span class="relative w-full sm:w-80">
                                            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                            <InputText
                                                v-model="schoolYearSearch"
                                                placeholder="Search by school year"
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
                                                :loading="schoolYearLoading"
                                                @click="onRefreshSchoolYears"
                                                aria-label="Refresh"
                                            />
                                            <Button label="Add School Year" icon="pi pi-plus" @click="openAddSchoolYear" />
                                        </div>
                                    </template>
                                </Toolbar>

                                <!-- School Years Table -->
                                <DataTable
                                    :value="schoolYears.data"
                                    :loading="schoolYearLoading"
                                    dataKey="id"
                                    class="rounded-xl overflow-hidden"
                                    stripedRows
                                    responsiveLayout="scroll"
                                    lazy
                                    paginator
                                    :rows="schoolYears.per_page"
                                    :totalRecords="schoolYears.total"
                                    :first="(schoolYears.current_page - 1) * schoolYears.per_page"
                                    @page="onSchoolYearPage"
                                >
                                    <template #empty>
                                        <div class="text-center py-10">
                                            <p class="text-slate-500 font-medium">No school years found.</p>
                                            <Button
                                                label="Add School Year"
                                                icon="pi pi-plus"
                                                class="mt-3"
                                                @click="openAddSchoolYear"
                                            />
                                        </div>
                                    </template>

                                    <Column field="name" header="School Year" style="width: 12rem" />
                                    <Column field="start_year" header="Start Year" style="width: 10rem" />
                                    <Column field="end_year" header="End Year" style="width: 10rem" />
                                    <Column header="Status" style="width: 10rem">
                                        <template #body="{ data }">
                                            <Tag v-if="data.deleted_at" value="Deleted" severity="danger" />
                                            <Tag
                                                v-else
                                                :value="data.status"
                                                :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                            />
                                        </template>
                                    </Column>
                                    <Column header="Actions" style="width: 9rem">
                                        <template #body="{ data }">
                                            <div class="flex gap-1">
                                                <template v-if="!data.deleted_at">
                                                    <Button
                                                        icon="pi pi-pencil"
                                                        text
                                                        rounded
                                                        severity="secondary"
                                                        size="small"
                                                        aria-label="Edit"
                                                        @click="openEditSchoolYear(data)"
                                                    />
                                                    <Button
                                                        icon="pi pi-trash"
                                                        text
                                                        rounded
                                                        severity="danger"
                                                        size="small"
                                                        aria-label="Delete"
                                                        @click="onDeleteSchoolYear(data)"
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
                                                    @click="onRestoreSchoolYear(data)"
                                                />
                                            </div>
                                        </template>
                                    </Column>
                                </DataTable>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- Semesters -->
                    <TabPanel value="semesters">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm mt-4">
                            <template #content>
                                <!-- Top Toolbar -->
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3">
                                    <template #start>
                                        <span class="relative w-full sm:w-80">
                                            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                            <InputText
                                                v-model="semesterSearch"
                                                placeholder="Search by semester or short name"
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
                                                :loading="semesterLoading"
                                                @click="onRefreshSemesters"
                                                aria-label="Refresh"
                                            />
                                            <Button label="Add Semester" icon="pi pi-plus" @click="openAddSemester" />
                                        </div>
                                    </template>
                                </Toolbar>

                                <!-- Semesters Table -->
                                <DataTable
                                    :value="semesters.data"
                                    :loading="semesterLoading"
                                    dataKey="id"
                                    class="rounded-xl overflow-hidden"
                                    stripedRows
                                    responsiveLayout="scroll"
                                    lazy
                                    paginator
                                    :rows="semesters.per_page"
                                    :totalRecords="semesters.total"
                                    :first="(semesters.current_page - 1) * semesters.per_page"
                                    @page="onSemesterPage"
                                >
                                    <template #empty>
                                        <div class="text-center py-10">
                                            <p class="text-slate-500 font-medium">No semesters found.</p>
                                            <Button
                                                label="Add Semester"
                                                icon="pi pi-plus"
                                                class="mt-3"
                                                @click="openAddSemester"
                                            />
                                        </div>
                                    </template>

                                    <Column field="name" header="Semester" style="width: 14rem" />
                                    <Column field="short_name" header="Short Name" style="width: 10rem" />
                                    <Column field="display_order" header="Display Order" style="width: 10rem" />
                                    <Column header="Status" style="width: 10rem">
                                        <template #body="{ data }">
                                            <Tag v-if="data.deleted_at" value="Deleted" severity="danger" />
                                            <Tag
                                                v-else
                                                :value="data.status"
                                                :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                            />
                                        </template>
                                    </Column>
                                    <Column header="Actions" style="width: 9rem">
                                        <template #body="{ data }">
                                            <div class="flex gap-1">
                                                <template v-if="!data.deleted_at">
                                                    <Button
                                                        icon="pi pi-pencil"
                                                        text
                                                        rounded
                                                        severity="secondary"
                                                        size="small"
                                                        aria-label="Edit"
                                                        @click="openEditSemester(data)"
                                                    />
                                                    <Button
                                                        icon="pi pi-trash"
                                                        text
                                                        rounded
                                                        severity="danger"
                                                        size="small"
                                                        aria-label="Delete"
                                                        @click="onDeleteSemester(data)"
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
                                                    @click="onRestoreSemester(data)"
                                                />
                                            </div>
                                        </template>
                                    </Column>
                                </DataTable>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- Academic Terms -->
                    <TabPanel value="academic-terms">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm mt-4">
                            <template #content>
                                <!-- Top Toolbar -->
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3">
                                    <template #start>
                                        <span class="relative w-full sm:w-80">
                                            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                            <InputText
                                                v-model="academicTermSearch"
                                                placeholder="Search by school year or semester"
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
                                                :loading="academicTermLoading"
                                                @click="onRefreshAcademicTerms"
                                                aria-label="Refresh"
                                            />
                                            <Button label="Add Academic Term" icon="pi pi-plus" @click="openAddAcademicTerm" />
                                        </div>
                                    </template>
                                </Toolbar>

                                <!-- Academic Terms Table -->
                                <DataTable
                                    :value="academicTerms.data"
                                    :loading="academicTermLoading"
                                    dataKey="id"
                                    class="rounded-xl overflow-hidden"
                                    stripedRows
                                    responsiveLayout="scroll"
                                    lazy
                                    paginator
                                    :rows="academicTerms.per_page"
                                    :totalRecords="academicTerms.total"
                                    :first="(academicTerms.current_page - 1) * academicTerms.per_page"
                                    @page="onAcademicTermPage"
                                >
                                    <template #empty>
                                        <div class="text-center py-10">
                                            <p class="text-slate-500 font-medium">No academic terms found.</p>
                                            <Button
                                                label="Add Academic Term"
                                                icon="pi pi-plus"
                                                class="mt-3"
                                                @click="openAddAcademicTerm"
                                            />
                                        </div>
                                    </template>

                                    <Column header="School Year" style="width: 12rem">
                                        <template #body="{ data }">
                                            {{ data.school_year?.name }}
                                        </template>
                                    </Column>
                                    <Column header="Semester" style="width: 14rem">
                                        <template #body="{ data }">
                                            {{ data.semester?.name }}
                                        </template>
                                    </Column>
                                    <Column header="Status" style="width: 10rem">
                                        <template #body="{ data }">
                                            <Tag v-if="data.deleted_at" value="Deleted" severity="danger" />
                                            <Tag
                                                v-else
                                                :value="data.status"
                                                :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                            />
                                        </template>
                                    </Column>
                                    <Column field="remarks" header="Remarks">
                                        <template #body="{ data }">
                                            <span class="text-slate-500">{{ data.remarks || '—' }}</span>
                                        </template>
                                    </Column>
                                    <Column header="Actions" style="width: 9rem">
                                        <template #body="{ data }">
                                            <div class="flex gap-1">
                                                <template v-if="!data.deleted_at">
                                                    <Button
                                                        icon="pi pi-pencil"
                                                        text
                                                        rounded
                                                        severity="secondary"
                                                        size="small"
                                                        aria-label="Edit"
                                                        @click="openEditAcademicTerm(data)"
                                                    />
                                                    <Button
                                                        icon="pi pi-trash"
                                                        text
                                                        rounded
                                                        severity="danger"
                                                        size="small"
                                                        aria-label="Delete"
                                                        @click="onDeleteAcademicTerm(data)"
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
                                                    @click="onRestoreAcademicTerm(data)"
                                                />
                                            </div>
                                        </template>
                                    </Column>
                                </DataTable>
                            </template>
                        </Card>
                    </TabPanel>
                </TabPanels>
            </Tabs>
        </div>

        <!-- Add / Edit School Year Modal -->
        <Dialog
            v-model:visible="schoolYearDialogVisible"
            modal
            :style="{ width: '520px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
        >
            <template #header>
                <span class="text-lg font-bold text-[#1E293B]">
                    {{ schoolYearDialogMode === 'add' ? 'Add School Year' : 'Edit School Year' }}
                </span>
            </template>

            <form class="pt-2" autocomplete="off" @submit.prevent="onSaveSchoolYear">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <InputNumber
                            id="schoolYearStart"
                            v-model="schoolYearForm.start_year"
                            class="w-full"
                            :useGrouping="false"
                            :invalid="!!schoolYearForm.errors.start_year"
                        />
                        <label for="schoolYearStart">Start Year *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputNumber
                            id="schoolYearEnd"
                            v-model="schoolYearForm.end_year"
                            class="w-full"
                            :useGrouping="false"
                            :invalid="!!schoolYearForm.errors.end_year"
                        />
                        <label for="schoolYearEnd">End Year *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-1">
                    <small v-if="schoolYearForm.errors.start_year" class="text-red-500">{{ schoolYearForm.errors.start_year }}</small>
                    <small v-if="schoolYearForm.errors.end_year" class="text-red-500">{{ schoolYearForm.errors.end_year }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Select
                            id="schoolYearStatus"
                            v-model="schoolYearForm.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!schoolYearForm.errors.status"
                        />
                        <label for="schoolYearStatus">Status *</label>
                    </FloatLabel>
                    <small v-if="schoolYearForm.errors.status" class="text-red-500 -mt-4">{{ schoolYearForm.errors.status }}</small>
                    <p v-if="schoolYearForm.status === 'Active'" class="text-xs text-slate-400 -mt-4">
                        Setting this School Year Active will automatically set every other School Year to Inactive.
                    </p>
                </div>

                <!-- Read-only preview of the auto-generated name -->
                <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">School Year</p>
                    <p class="text-lg font-bold text-[#1E293B]">{{ schoolYearNamePreview }}</p>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="schoolYearForm.processing" @click="closeSchoolYearDialog" />
                <Button label="Save" icon="pi pi-check" :loading="schoolYearForm.processing" @click="onSaveSchoolYear" />
            </template>
        </Dialog>

        <!-- Add / Edit Semester Modal -->
        <Dialog
            v-model:visible="semesterDialogVisible"
            modal
            :style="{ width: '520px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
        >
            <template #header>
                <span class="text-lg font-bold text-[#1E293B]">
                    {{ semesterDialogMode === 'add' ? 'Add Semester' : 'Edit Semester' }}
                </span>
            </template>

            <form class="pt-2" autocomplete="off" @submit.prevent="onSaveSemester">
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="semesterName"
                            v-model="semesterForm.name"
                            class="w-full"
                            :invalid="!!semesterForm.errors.name"
                            maxlength="100"
                        />
                        <label for="semesterName">Semester Name *</label>
                    </FloatLabel>
                    <small v-if="semesterForm.errors.name" class="text-red-500 -mt-4">{{ semesterForm.errors.name }}</small>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="semesterShortName"
                            v-model="semesterForm.short_name"
                            class="w-full"
                            :invalid="!!semesterForm.errors.short_name"
                            maxlength="30"
                        />
                        <label for="semesterShortName">Short Name *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputNumber
                            id="semesterDisplayOrder"
                            v-model="semesterForm.display_order"
                            class="w-full"
                            :useGrouping="false"
                            :invalid="!!semesterForm.errors.display_order"
                        />
                        <label for="semesterDisplayOrder">Display Order *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-1">
                    <small v-if="semesterForm.errors.short_name" class="text-red-500">{{ semesterForm.errors.short_name }}</small>
                    <small v-if="semesterForm.errors.display_order" class="text-red-500">{{ semesterForm.errors.display_order }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Select
                            id="semesterStatus"
                            v-model="semesterForm.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!semesterForm.errors.status"
                        />
                        <label for="semesterStatus">Status *</label>
                    </FloatLabel>
                    <small v-if="semesterForm.errors.status" class="text-red-500 -mt-4">{{ semesterForm.errors.status }}</small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="semesterForm.processing" @click="closeSemesterDialog" />
                <Button label="Save" icon="pi pi-check" :loading="semesterForm.processing" @click="onSaveSemester" />
            </template>
        </Dialog>

        <!-- Add / Edit Academic Term Modal -->
        <Dialog
            v-model:visible="academicTermDialogVisible"
            modal
            :style="{ width: '520px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
        >
            <template #header>
                <span class="text-lg font-bold text-[#1E293B]">
                    {{ academicTermDialogMode === 'add' ? 'Add Academic Term' : 'Edit Academic Term' }}
                </span>
            </template>

            <form class="pt-2" autocomplete="off" @submit.prevent="onSaveAcademicTerm">
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="academicTermSchoolYear"
                            v-model="academicTermForm.school_year_id"
                            :options="activeSchoolYears"
                            optionLabel="name"
                            optionValue="id"
                            class="w-full"
                            :invalid="!!academicTermForm.errors.school_year_id"
                        />
                        <label for="academicTermSchoolYear">School Year *</label>
                    </FloatLabel>
                    <small v-if="academicTermForm.errors.school_year_id" class="text-red-500 -mt-4">{{ academicTermForm.errors.school_year_id }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Select
                            id="academicTermSemester"
                            v-model="academicTermForm.semester_id"
                            :options="activeSemesters"
                            optionLabel="name"
                            optionValue="id"
                            class="w-full"
                            :invalid="!!academicTermForm.errors.semester_id"
                        />
                        <label for="academicTermSemester">Semester *</label>
                    </FloatLabel>
                    <small v-if="academicTermForm.errors.semester_id" class="text-red-500 -mt-4">{{ academicTermForm.errors.semester_id }}</small>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Select
                            id="academicTermStatus"
                            v-model="academicTermForm.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!academicTermForm.errors.status"
                        />
                        <label for="academicTermStatus">Status *</label>
                    </FloatLabel>
                    <small v-if="academicTermForm.errors.status" class="text-red-500 -mt-4">{{ academicTermForm.errors.status }}</small>
                    <p v-if="academicTermForm.status === 'Active'" class="text-xs text-slate-400 -mt-4">
                        Setting this Academic Term Active will automatically set every other Academic Term to Inactive.
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="academicTermRemarks"
                            v-model="academicTermForm.remarks"
                            class="w-full"
                            :invalid="!!academicTermForm.errors.remarks"
                            maxlength="255"
                        />
                        <label for="academicTermRemarks">Remarks</label>
                    </FloatLabel>
                    <small v-if="academicTermForm.errors.remarks" class="text-red-500 -mt-4">{{ academicTermForm.errors.remarks }}</small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="academicTermForm.processing" @click="closeAcademicTermDialog" />
                <Button label="Save" icon="pi pi-check" :loading="academicTermForm.processing" @click="onSaveAcademicTerm" />
            </template>
        </Dialog>
    </AppLayout>
</template>