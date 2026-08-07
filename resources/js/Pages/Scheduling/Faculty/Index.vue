<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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

const props = defineProps({
    faculties: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    filters: {
        type: Object,
        default: () => ({ faculty_search: '', faculty_category: '' }),
    },
    colleges: { type: Array, default: () => [] },
    nextFacultyId: { type: String, default: '' },
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

const search = ref(props.filters.faculty_search ?? '');
const categoryFilter = ref(props.filters.faculty_category ?? '');
const loading = ref(false);
let searchDebounce = null;

// Not a stored field — General Education Faculty is simply "no College
// assigned". Kept as a quick filter option since it's still a useful
// distinction, just derived rather than tracked separately.
const facultyCategoryOptions = ['Department Faculty', 'General Education Faculty'];

const reloadFaculties = (extra = {}) => {
    loading.value = true;

    router.get(
        route('scheduling.faculty'),
        { faculty_search: search.value, faculty_category: categoryFilter.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['faculties'],
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        reloadFaculties({ faculty_page: 1 });
    }, 350);
});

watch(categoryFilter, () => {
    reloadFaculties({ faculty_page: 1 });
});

const onPage = (event) => {
    reloadFaculties({ faculty_page: event.page + 1 });
};

const onRefresh = () => {
    reloadFaculties({ faculty_page: props.faculties.current_page });
};

/* ------------------------------------------------------------------ */
/* Add / Edit Faculty                                                   */
/* ------------------------------------------------------------------ */

const employmentTypeOptions = ['Full-time', 'Part-time', 'Contractual'];
const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

const addFacultyVisible = ref(false);
const editingFaculty = ref(null); // null => Add mode, otherwise the Faculty being edited

const facultyForm = useForm({
    faculty_id: '',
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    employment_type: null,
    college_id: null,
    max_teaching_units: 21,
    status: 'Active',
    email: '',
    contact_number: '',
    remarks: '',
});

// Faculty ID, name, suffix, contact number, and remarks are always
// stored/displayed in caps (matches the table); Email is left as
// typed since addresses are case-sensitive-looking to users.
const UPPERCASE_FIELDS = ['faculty_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'contact_number', 'remarks'];
UPPERCASE_FIELDS.forEach((field) => {
    watch(
        () => facultyForm[field],
        (value) => {
            if (typeof value === 'string' && value !== value.toUpperCase()) {
                facultyForm[field] = value.toUpperCase();
            }
        },
    );
});

const openAdd = () => {
    editingFaculty.value = null;
    facultyForm.reset();
    facultyForm.clearErrors();
    facultyForm.faculty_id = props.nextFacultyId;
    addFacultyVisible.value = true;
};

const openEdit = (faculty) => {
    editingFaculty.value = faculty;
    facultyForm.clearErrors();
    facultyForm.faculty_id = faculty.faculty_id;
    facultyForm.first_name = faculty.first_name;
    facultyForm.middle_name = faculty.middle_name ?? '';
    facultyForm.last_name = faculty.last_name;
    facultyForm.suffix = faculty.suffix ?? '';
    facultyForm.employment_type = faculty.employment_type;
    facultyForm.college_id = faculty.college_id;
    facultyForm.max_teaching_units = faculty.max_teaching_units;
    facultyForm.status = faculty.status;
    facultyForm.email = faculty.email ?? '';
    facultyForm.contact_number = faculty.contact_number ?? '';
    facultyForm.remarks = faculty.remarks ?? '';
    addFacultyVisible.value = true;
};

const closeAddFaculty = () => {
    addFacultyVisible.value = false;
    editingFaculty.value = null;
    facultyForm.reset();
    facultyForm.clearErrors();
};

const onSaveFaculty = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            const wasEditing = !!editingFaculty.value;
            closeAddFaculty();
            Swal.fire({
                title: wasEditing ? 'Faculty updated' : 'Faculty saved',
                text: wasEditing
                    ? 'The faculty record was updated successfully.'
                    : 'The faculty member was added successfully.',
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

    if (editingFaculty.value) {
        facultyForm.put(route('scheduling.faculty.update', editingFaculty.value.id), options);
    } else {
        facultyForm.post(route('scheduling.faculty.store'), options);
    }
};

const onDeleteFaculty = (faculty) => {
    Swal.fire({
        title: 'Delete this faculty member?',
        text: `${faculty.faculty_id} — ${faculty.first_name} ${faculty.last_name} will be permanently deleted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('scheduling.faculty.destroy', faculty.id), {
                preserveScroll: true,
                onSuccess: () => onRefresh(),
            });
        }
    });
};

const fullName = (faculty) => {
    const middleInitial = faculty.middle_name ? ` ${faculty.middle_name.charAt(0)}.` : '';
    const suffix = faculty.suffix ? ` ${faculty.suffix}` : '';
    return `${faculty.last_name}, ${faculty.first_name}${middleInitial}${suffix}`;
};
</script>

<template>
    <Head title="Faculty Master" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Faculty Master</span>
        </template>

        <div class="max-w-7xl mx-auto w-full">
            <!-- Page Title -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">Faculty Master</h1>
                <p class="mt-1 text-slate-500">
                    Manage faculty members available for teaching assignments.
                </p>
            </div>

            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <!-- Top Toolbar -->
                    <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3">
                        <template #start>
                            <div class="flex flex-wrap items-center gap-3 w-full">
                                <span class="relative w-full sm:w-80">
                                    <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                    <InputText
                                        v-model="search"
                                        placeholder="Search by ID, name, or college"
                                        class="w-full !pl-9"
                                    />
                                </span>
                                <Select
                                    v-model="categoryFilter"
                                    :options="facultyCategoryOptions"
                                    placeholder="Filter by Department / General Education"
                                    showClear
                                    class="w-full sm:w-64"
                                />
                            </div>
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
                                <Button label="Add Faculty" icon="pi pi-plus" severity="success" @click="openAdd" />
                            </div>
                        </template>
                    </Toolbar>

                    <!-- Faculty Table -->
                    <p class="text-xs text-slate-400 mb-2 flex items-center gap-1">
                        <i class="pi pi-eye text-slate-400"></i>
                        Click the view icon on a row to manage that faculty member's teaching qualifications, availability, and workload.
                    </p>
                    <DataTable
                        :value="faculties.data"
                        :loading="loading"
                        dataKey="id"
                        class="rounded-xl overflow-hidden"
                        stripedRows
                        responsiveLayout="scroll"
                        lazy
                        paginator
                        :rows="faculties.per_page"
                        :totalRecords="faculties.total"
                        :first="(faculties.current_page - 1) * faculties.per_page"
                        @page="onPage"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No faculty members found.</p>
                                <p class="text-slate-400 text-sm mt-1">
                                    Click "Add Faculty" to create your first faculty record.
                                </p>
                                <Button
                                    label="Add Faculty"
                                    icon="pi pi-plus"
                                    severity="success"
                                    class="mt-3"
                                    @click="openAdd"
                                />
                            </div>
                        </template>

                        <Column field="faculty_id" header="Faculty ID" style="width: 9rem" />
                        <Column header="Faculty Name" style="min-width: 14rem">
                            <template #body="{ data }">
                                {{ fullName(data) }}
                            </template>
                        </Column>
                        <Column header="Employment Status" style="width: 10rem">
                            <template #body="{ data }">
                                {{ data.employment_type }}
                            </template>
                        </Column>
                        <Column header="College" style="width: 16rem">
                            <template #body="{ data }">
                                <span v-if="data.college?.name">{{ data.college.name }}</span>
                                <Tag v-else value="General Education" severity="warning" />
                            </template>
                        </Column>
                        <Column header="Max Teaching Units" style="width: 9rem">
                            <template #body="{ data }">
                                {{ data.max_teaching_units }}
                            </template>
                        </Column>
                        <Column header="Teaching Load" style="width: 11rem">
                            <template #body="{ data }">
                                <div v-if="data.workload" class="flex items-center gap-1.5">
                                    <span>
                                        {{ data.workload.status === 'overloaded' ? '🔴' : data.workload.status === 'high' ? '🟡' : '🟢' }}
                                    </span>
                                    <span class="text-sm text-slate-700">
                                        {{ data.workload.current }} / {{ data.workload.max }} {{ data.workload.unit_label }}
                                    </span>
                                </div>
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
                        <Column header="Actions" style="width: 9rem">
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <Button
                                        v-tooltip.top="'View details, teaching qualifications, availability & workload'"
                                        icon="pi pi-eye"
                                        text
                                        rounded
                                        severity="info"
                                        size="small"
                                        aria-label="View"
                                        @click="router.visit(route('scheduling.faculty.show', data.id))"
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
                                        @click="onDeleteFaculty(data)"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>

        <!-- Add Faculty Dialog -->
        <Dialog
            v-model:visible="addFacultyVisible"
            modal
            :header="editingFaculty ? 'Edit Faculty' : 'Add Faculty'"
            :style="{ width: '760px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            @hide="closeAddFaculty"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4" @submit.prevent="onSaveFaculty">
                <!-- Faculty ID -->
                <div class="flex flex-col gap-1">
                    <label for="faculty_id" class="text-sm font-medium text-slate-700">
                        Faculty ID <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="faculty_id"
                        v-model="facultyForm.faculty_id"
                        placeholder="e.g. F-2026-001"
                        :invalid="!!facultyForm.errors.faculty_id"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.faculty_id" class="text-red-500">
                        {{ facultyForm.errors.faculty_id }}
                    </small>
                    <p v-else-if="!editingFaculty" class="text-xs text-slate-400">
                        Suggested next ID — feel free to change it.
                    </p>
                </div>

                <!-- Employment Type -->
                <div class="flex flex-col gap-1">
                    <label for="employment_type" class="text-sm font-medium text-slate-700">
                        Employment Type <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="employment_type"
                        v-model="facultyForm.employment_type"
                        :options="employmentTypeOptions"
                        placeholder="Select employment type"
                        :invalid="!!facultyForm.errors.employment_type"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.employment_type" class="text-red-500">
                        {{ facultyForm.errors.employment_type }}
                    </small>
                </div>

                <!-- First Name -->
                <div class="flex flex-col gap-1">
                    <label for="first_name" class="text-sm font-medium text-slate-700">
                        First Name <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="first_name"
                        v-model="facultyForm.first_name"
                        placeholder="e.g. Juan"
                        :invalid="!!facultyForm.errors.first_name"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.first_name" class="text-red-500">
                        {{ facultyForm.errors.first_name }}
                    </small>
                </div>

                <!-- Middle Name -->
                <div class="flex flex-col gap-1">
                    <label for="middle_name" class="text-sm font-medium text-slate-700">Middle Name</label>
                    <InputText
                        id="middle_name"
                        v-model="facultyForm.middle_name"
                        placeholder="e.g. Santos"
                        :invalid="!!facultyForm.errors.middle_name"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.middle_name" class="text-red-500">
                        {{ facultyForm.errors.middle_name }}
                    </small>
                </div>

                <!-- Last Name -->
                <div class="flex flex-col gap-1">
                    <label for="last_name" class="text-sm font-medium text-slate-700">
                        Last Name <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="last_name"
                        v-model="facultyForm.last_name"
                        placeholder="e.g. Dela Cruz"
                        :invalid="!!facultyForm.errors.last_name"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.last_name" class="text-red-500">
                        {{ facultyForm.errors.last_name }}
                    </small>
                </div>

                <!-- Suffix -->
                <div class="flex flex-col gap-1">
                    <label for="suffix" class="text-sm font-medium text-slate-700">Suffix</label>
                    <InputText
                        id="suffix"
                        v-model="facultyForm.suffix"
                        placeholder="e.g. Jr., III"
                        :invalid="!!facultyForm.errors.suffix"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.suffix" class="text-red-500">
                        {{ facultyForm.errors.suffix }}
                    </small>
                </div>

                <!-- College -->
                <div class="flex flex-col gap-1">
                    <label for="college_id" class="text-sm font-medium text-slate-700">College</label>
                    <Select
                        id="college_id"
                        v-model="facultyForm.college_id"
                        :options="colleges"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Leave blank for General Education"
                        showClear
                        :invalid="!!facultyForm.errors.college_id"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.college_id" class="text-red-500">
                        {{ facultyForm.errors.college_id }}
                    </small>
                    <p v-else class="text-xs text-slate-400">
                        Leave blank if this faculty member is General Education (no department).
                    </p>
                </div>

                <!-- Maximum Teaching Units -->
                <div class="flex flex-col gap-1">
                    <label for="max_teaching_units" class="text-sm font-medium text-slate-700">
                        Maximum Teaching Units <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="max_teaching_units"
                        v-model="facultyForm.max_teaching_units"
                        :min="0"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!facultyForm.errors.max_teaching_units"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="facultyForm.errors.max_teaching_units" class="text-red-500">
                        {{ facultyForm.errors.max_teaching_units }}
                    </small>
                </div>

                <!-- Status -->
                <div class="flex flex-col gap-1">
                    <label for="status" class="text-sm font-medium text-slate-700">
                        Status <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="status"
                        v-model="facultyForm.status"
                        :options="statusOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select status"
                        :invalid="!!facultyForm.errors.status"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.status" class="text-red-500">
                        {{ facultyForm.errors.status }}
                    </small>
                </div>

                <!-- Email -->
                <div class="flex flex-col gap-1">
                    <label for="email" class="text-sm font-medium text-slate-700">Email</label>
                    <InputText
                        id="email"
                        v-model="facultyForm.email"
                        type="email"
                        placeholder="Optional"
                        :invalid="!!facultyForm.errors.email"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.email" class="text-red-500">
                        {{ facultyForm.errors.email }}
                    </small>
                </div>

                <!-- Contact Number -->
                <div class="flex flex-col gap-1">
                    <label for="contact_number" class="text-sm font-medium text-slate-700">Contact Number</label>
                    <InputText
                        id="contact_number"
                        v-model="facultyForm.contact_number"
                        placeholder="Optional"
                        :invalid="!!facultyForm.errors.contact_number"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.contact_number" class="text-red-500">
                        {{ facultyForm.errors.contact_number }}
                    </small>
                </div>

                <!-- Remarks -->
                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label for="remarks" class="text-sm font-medium text-slate-700">Remarks</label>
                    <Textarea
                        id="remarks"
                        v-model="facultyForm.remarks"
                        autoResize
                        rows="3"
                        placeholder="Optional notes about this faculty member"
                        :invalid="!!facultyForm.errors.remarks"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.remarks" class="text-red-500">
                        {{ facultyForm.errors.remarks }}
                    </small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeAddFaculty" />
                <Button
                    :label="editingFaculty ? 'Update Faculty' : 'Save Faculty'"
                    icon="pi pi-check"
                    severity="success"
                    :loading="facultyForm.processing"
                    @click="onSaveFaculty"
                />
            </template>
        </Dialog>
    </AppLayout>
</template>