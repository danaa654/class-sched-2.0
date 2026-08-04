<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Password from 'primevue/password';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import Divider from 'primevue/divider';
import FloatLabel from 'primevue/floatlabel';
import Checkbox from 'primevue/checkbox';
import Toast from 'primevue/toast';

const props = defineProps({
    users: { type: Array, default: () => [] },
    colleges: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    nextEmployeeId: { type: String, default: '' },
});

const toast = useToast();
const page = usePage();

// Show a toast whenever the backend flashes a success/error message
// (e.g. right after Create User actually saves).
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

const search = ref('');

/* ------------------------------------------------------------------ */
/* Add User modal                                                      */
/* ------------------------------------------------------------------ */

const addUserVisible = ref(false);

const roleOptions = [
    { label: 'Administrator', value: 'Administrator' },
    { label: 'Registrar', value: 'Registrar' },
    { label: 'Assistant Dean', value: 'Assistant Dean' },
    { label: 'Dean', value: 'Dean' },
    { label: 'OIC', value: 'OIC' },
];

const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

const collegeOptions = computed(() =>
    props.colleges.map((college) => ({ label: college.name, value: college.id })),
);

// Only show departments belonging to the selected college.
const departmentOptions = computed(() => {
    if (!form.college_id) {
        return [];
    }

    return props.departments
        .filter((department) => department.college_id === form.college_id)
        .map((department) => ({ label: department.name, value: department.id }));
});

const form = useForm({
    employee_id: '',
    role: null,
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    email: '',
    password: '',
    password_confirmation: '',
    college_id: null,
    department_ids: [],
    oversees_all_departments: false,
    status: 'Active',
});

// Dean oversees the whole college — only College is needed.
// OIC may cover any subset of departments — College + Department(s) needed.
const rolesRequiringCollege = ['Dean', 'OIC'];
const rolesRequiringDepartment = ['OIC'];

const showCollege = computed(() => rolesRequiringCollege.includes(form.role));
const showDepartment = computed(
    () => rolesRequiringDepartment.includes(form.role) && !form.oversees_all_departments,
);

// Clear department selections if the college changes so a stale
// mismatch (departments from a different college) can't be submitted.
watch(
    () => form.college_id,
    () => {
        form.department_ids = [];
    },
);

// Clear college/department entirely if role no longer requires them.
watch(
    () => form.role,
    () => {
        if (!showCollege.value) {
            form.college_id = null;
        }
        if (!rolesRequiringDepartment.includes(form.role)) {
            form.department_ids = [];
            form.oversees_all_departments = false;
        }
    },
);

// If the admin checks "oversees all departments", clear the specific picks.
watch(
    () => form.oversees_all_departments,
    (checked) => {
        if (checked) {
            form.department_ids = [];
        }
    },
);

const openAddUser = () => {
    form.reset();
    form.clearErrors();
    form.employee_id = props.nextEmployeeId;
    addUserVisible.value = true;
};

const closeAddUser = () => {
    addUserVisible.value = false;
    form.reset();
    form.clearErrors();
};

const onCreateUser = () => {
    form.post(route('users.store'), {
        preserveScroll: true,
        onSuccess: () => {
            closeAddUser();
        },
        onError: () => {
            toast.add({
                severity: 'warn',
                summary: 'Missing information',
                detail: 'Please check the highlighted fields and try again.',
                life: 3000,
            });
        },
    });
};
</script>

<template>
    <Head title="User Management" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">User Management</span>
        </template>

        <div class="max-w-7xl mx-auto w-full">
            <!-- Page Title -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">User Management</h1>
                <p class="mt-1 text-slate-500">
                    Manage system accounts and role assignments.
                </p>
            </div>

            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <!-- Top Toolbar -->
                    <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4">
                        <template #start>
                            <span class="relative w-full sm:w-80">
                                <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <InputText
                                    v-model="search"
                                    placeholder="Search by employee ID, name or email"
                                    class="w-full !pl-9"
                                />
                            </span>
                        </template>
                        <template #end>
                            <Button label="Add User" icon="pi pi-plus" @click="openAddUser" />
                        </template>
                    </Toolbar>

                    <!-- Users Table -->
                    <DataTable
                        :value="users"
                        class="rounded-xl overflow-hidden"
                        stripedRows
                        responsiveLayout="scroll"
                        :globalFilterFields="['employeeId', 'fullName', 'email']"
                        :filters="{ global: { value: search, matchMode: 'contains' } }"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No users found.</p>
                                <p class="text-sm text-slate-400 mt-1">Create your first account.</p>
                            </div>
                        </template>

                        <Column field="employeeId" header="Employee ID" />
                        <Column field="fullName" header="Full Name" />
                        <Column field="email" header="Email" />
                        <Column field="role" header="Role" />
                        <Column field="college" header="College" />
                        <Column field="department" header="Department" />
                        <Column field="status" header="Status">
                            <template #body="{ data }">
                                <Tag
                                    :value="data.status"
                                    :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                />
                            </template>
                        </Column>
                        <Column header="Actions" style="width: 9rem">
                            <template #body>
                                <div class="flex gap-1">
                                    <Button icon="pi pi-pencil" text rounded severity="secondary" size="small" />
                                    <Button icon="pi pi-eye" text rounded severity="secondary" size="small" />
                                    <Button icon="pi pi-ellipsis-v" text rounded severity="secondary" size="small" />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>

        <!-- Add User Modal -->
        <Dialog
            v-model:visible="addUserVisible"
            modal
            :style="{ width: '800px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
        >
            <template #header>
                <span class="text-lg font-bold text-[#1E293B]">Create System User</span>
            </template>

            <form class="pt-2" autocomplete="off" @submit.prevent="onCreateUser">
                <!-- Employee ID / Role -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="employeeId"
                            v-model="form.employee_id"
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!form.errors.employee_id"
                        />
                        <label for="employeeId">Employee ID *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Select
                            id="role"
                            v-model="form.role"
                            :options="roleOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!form.errors.role"
                        />
                        <label for="role">Role *</label>
                    </FloatLabel>
                </div>
                <small v-if="form.errors.role" class="text-red-500">{{ form.errors.role }}</small>
                <small v-if="form.errors.employee_id" class="text-red-500 block">{{ form.errors.employee_id }}</small>
                <p v-if="!form.errors.employee_id" class="text-xs text-slate-400 mt-1">
                    Suggested next ID — feel free to change it.
                </p>

                <Divider class="!my-5" />

                <!-- First Name / Middle Name -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="firstName"
                            v-model="form.first_name"
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!form.errors.first_name"
                        />
                        <label for="firstName">First Name *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputText
                            id="middleName"
                            v-model="form.middle_name"
                            class="w-full"
                            autocomplete="off"
                        />
                        <label for="middleName">Middle Name</label>
                    </FloatLabel>
                </div>

                <!-- Last Name / Suffix -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="lastName"
                            v-model="form.last_name"
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!form.errors.last_name"
                        />
                        <label for="lastName">Last Name *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputText
                            id="suffix"
                            v-model="form.suffix"
                            class="w-full"
                            autocomplete="off"
                        />
                        <label for="suffix">Suffix</label>
                    </FloatLabel>
                </div>

                <Divider class="!my-5" />

                <!-- Email -->
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="email"
                            v-model="form.email"
                            type="email"
                            class="w-full"
                            autocomplete="off"
                            :invalid="!!form.errors.email"
                        />
                        <label for="email">Email *</label>
                    </FloatLabel>
                    <small v-if="form.errors.email" class="text-red-500 -mt-4">{{ form.errors.email }}</small>
                </div>

                <!-- Password / Confirm Password -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Password
                            id="password"
                            v-model="form.password"
                            toggleMask
                            :feedback="true"
                            inputClass="w-full"
                            class="w-full"
                            autocomplete="new-password"
                            :invalid="!!form.errors.password"
                        />
                        <label for="password">Password *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Password
                            id="confirmPassword"
                            v-model="form.password_confirmation"
                            toggleMask
                            :feedback="false"
                            inputClass="w-full"
                            class="w-full"
                            autocomplete="new-password"
                            :invalid="!!form.errors.password"
                        />
                        <label for="confirmPassword">Confirm Password *</label>
                    </FloatLabel>
                </div>
                <small v-if="form.errors.password" class="text-red-500">{{ form.errors.password }}</small>

                <template v-if="showCollege">
                    <Divider class="!my-5" />

                    <!-- College (Dean / OIC) / Department (OIC only) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <FloatLabel variant="on">
                            <Select
                                id="college"
                                v-model="form.college_id"
                                :options="collegeOptions"
                                optionLabel="label"
                                optionValue="value"
                                class="w-full"
                                :invalid="!!form.errors.college_id"
                            />
                            <label for="college">College *</label>
                        </FloatLabel>

                        <FloatLabel v-if="showDepartment" variant="on">
                            <MultiSelect
                                id="department"
                                v-model="form.department_ids"
                                :options="departmentOptions"
                                optionLabel="label"
                                optionValue="value"
                                display="chip"
                                class="w-full"
                                :disabled="!form.college_id"
                                :invalid="!!form.errors.department_ids"
                            />
                            <label for="department">Department(s) *</label>
                        </FloatLabel>
                    </div>
                    <small v-if="form.errors.department_ids" class="text-red-500">{{ form.errors.department_ids }}</small>

                    <!-- OIC scope: whole college vs a specific subset of departments -->
                    <div v-if="rolesRequiringDepartment.includes(form.role)" class="flex items-center gap-2 mt-4">
                        <Checkbox
                            id="oversees_all_departments"
                            v-model="form.oversees_all_departments"
                            binary
                            :disabled="!form.college_id"
                        />
                        <label for="oversees_all_departments" class="text-sm text-slate-600">
                            Oversees all departments in this college
                        </label>
                    </div>
                    <p class="text-xs text-slate-400 mt-1" v-if="showDepartment">
                        Select one or more departments this OIC will cover.
                    </p>
                </template>

                <Divider class="!my-5" />

                <!-- Status -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="status"
                            v-model="form.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!form.errors.status"
                        />
                        <label for="status">Status *</label>
                    </FloatLabel>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="form.processing" @click="closeAddUser" />
                <Button
                    label="Create User"
                    icon="pi pi-check"
                    :loading="form.processing"
                    @click="onCreateUser"
                />
            </template>
        </Dialog>
    </AppLayout>
</template>