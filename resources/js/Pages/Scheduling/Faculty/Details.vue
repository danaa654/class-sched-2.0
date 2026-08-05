<script setup>
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import Checkbox from 'primevue/checkbox';
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
    faculty: { type: Object, required: true },
    colleges: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    subjects: { type: Array, default: () => [] },
});

const toast = useToast();
const page = usePage();

watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) toast.add({ severity: 'success', summary: 'Success', detail: message, life: 4000 });
    },
);
watch(
    () => page.props.flash?.error,
    (message) => {
        if (message) toast.add({ severity: 'error', summary: 'Error', detail: message, life: 4000 });
    },
);

const activeTab = ref('information');

const fullName = computed(() => {
    const middleInitial = props.faculty.middle_name ? ` ${props.faculty.middle_name.charAt(0)}.` : '';
    const suffix = props.faculty.suffix ? ` ${props.faculty.suffix}` : '';
    return `${props.faculty.last_name}, ${props.faculty.first_name}${middleInitial}${suffix}`;
});

/* ------------------------------------------------------------------ */
/* Information tab                                                     */
/* ------------------------------------------------------------------ */

const employmentTypeOptions = ['Full-time', 'Part-time', 'Contractual'];
const facultyCategoryOptions = ['Department Faculty', 'General Education Faculty'];
const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

const editVisible = ref(false);

const filteredDepartments = computed(() => {
    if (!facultyForm.college_id) return [];
    return props.departments.filter((department) => department.college_id === facultyForm.college_id);
});

const isDepartmentFaculty = computed(() => facultyForm.faculty_category === 'Department Faculty');

const facultyForm = useForm({
    faculty_id: props.faculty.faculty_id,
    first_name: props.faculty.first_name,
    middle_name: props.faculty.middle_name,
    last_name: props.faculty.last_name,
    suffix: props.faculty.suffix,
    employment_type: props.faculty.employment_type,
    faculty_category: props.faculty.faculty_category ?? 'Department Faculty',
    college_id: props.faculty.college_id,
    department_id: props.faculty.department_id,
    specialization: props.faculty.specialization,
    max_teaching_units: props.faculty.max_teaching_units,
    status: props.faculty.status,
    email: props.faculty.email,
    contact_number: props.faculty.contact_number,
    remarks: props.faculty.remarks,
});

// General Education Faculty don't belong to a College/Department —
// clear both automatically whenever the category is switched away
// from Department Faculty.
watch(
    () => facultyForm.faculty_category,
    (category) => {
        if (category === 'General Education Faculty') {
            facultyForm.college_id = null;
            facultyForm.department_id = null;
        }
    },
);

const openEdit = () => {
    facultyForm.clearErrors();
    facultyForm.faculty_id = props.faculty.faculty_id;
    facultyForm.first_name = props.faculty.first_name;
    facultyForm.middle_name = props.faculty.middle_name;
    facultyForm.last_name = props.faculty.last_name;
    facultyForm.suffix = props.faculty.suffix;
    facultyForm.employment_type = props.faculty.employment_type;
    facultyForm.faculty_category = props.faculty.faculty_category ?? 'Department Faculty';
    facultyForm.college_id = props.faculty.college_id;
    facultyForm.department_id = props.faculty.department_id;
    facultyForm.specialization = props.faculty.specialization;
    facultyForm.max_teaching_units = props.faculty.max_teaching_units;
    facultyForm.status = props.faculty.status;
    facultyForm.email = props.faculty.email;
    facultyForm.contact_number = props.faculty.contact_number;
    facultyForm.remarks = props.faculty.remarks;
    editVisible.value = true;
};

const closeEdit = () => {
    editVisible.value = false;
    facultyForm.clearErrors();
};

const onSaveFaculty = () => {
    facultyForm.put(route('scheduling.faculty.update', props.faculty.id), {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
    });
};

/* ------------------------------------------------------------------ */
/* Teaching Qualifications tab                                         */
/* ------------------------------------------------------------------ */

const selectedSubjectIds = ref((props.faculty.subjects ?? []).map((subject) => subject.id));
const savingQualifications = ref(false);

watch(
    () => props.faculty.subjects,
    (subjects) => {
        selectedSubjectIds.value = (subjects ?? []).map((subject) => subject.id);
    },
);

const isQualificationsDirty = computed(() => {
    const current = new Set(selectedSubjectIds.value);
    const original = new Set((props.faculty.subjects ?? []).map((subject) => subject.id));
    if (current.size !== original.size) return true;
    for (const id of current) if (!original.has(id)) return true;
    return false;
});

const assignedSubjects = computed(() => {
    const bySubjectId = new Map(props.subjects.map((subject) => [subject.id, subject]));
    return selectedSubjectIds.value
        .map((id) => bySubjectId.get(id))
        .filter(Boolean)
        .sort((a, b) => a.subject_code.localeCompare(b.subject_code));
});

const removeSubject = (subjectId) => {
    selectedSubjectIds.value = selectedSubjectIds.value.filter((id) => id !== subjectId);
};

const saveQualifications = () => {
    savingQualifications.value = true;

    router.put(
        route('scheduling.teaching-qualifications.update', props.faculty.id),
        { subject_ids: selectedSubjectIds.value },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['faculty', 'flash'],
            onFinish: () => {
                savingQualifications.value = false;
            },
        },
    );
};

/* ------------------------------------------------------------------ */
/* Availability tab                                                    */
/* ------------------------------------------------------------------ */

const weekDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const allDayOptions = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

const availabilityByDay = computed(() => {
    const map = {};
    for (const record of props.faculty.availabilities ?? []) {
        map[record.day_of_week] = record;
    }
    return map;
});

// Days that don't have a record yet — used to keep "Add" from offering duplicates.
const availableDayOptions = computed(() => {
    const used = new Set((props.faculty.availabilities ?? []).map((record) => record.day_of_week));
    return allDayOptions.filter((day) => !used.has(day));
});

const formatTime = (time) => {
    if (!time) return '—';
    const [hour, minute] = time.split(':').map(Number);
    const period = hour >= 12 ? 'PM' : 'AM';
    const displayHour = hour % 12 === 0 ? 12 : hour % 12;
    return `${displayHour}:${String(minute).padStart(2, '0')} ${period}`;
};

const timeStringToDate = (time) => {
    if (!time) return null;
    const [hour, minute] = time.split(':').map(Number);
    const date = new Date();
    date.setHours(hour, minute, 0, 0);
    return date;
};

const dateToTimeString = (date) => {
    if (!date) return null;
    const hour = String(date.getHours()).padStart(2, '0');
    const minute = String(date.getMinutes()).padStart(2, '0');
    return `${hour}:${minute}`;
};

const availabilityDialogVisible = ref(false);
const editingAvailability = ref(null); // null => Add mode

const availabilityForm = useForm({
    day_of_week: null,
    is_available: true,
    start_time: null, // stored as HH:mm string
    end_time: null,
});

const startTimeModel = ref(null);
const endTimeModel = ref(null);

watch(startTimeModel, (value) => {
    availabilityForm.start_time = dateToTimeString(value);
});
watch(endTimeModel, (value) => {
    availabilityForm.end_time = dateToTimeString(value);
});

const openAddAvailability = () => {
    editingAvailability.value = null;
    availabilityForm.clearErrors();
    availabilityForm.day_of_week = availableDayOptions.value[0] ?? null;
    availabilityForm.is_available = true;
    availabilityForm.start_time = null;
    availabilityForm.end_time = null;
    startTimeModel.value = null;
    endTimeModel.value = null;
    availabilityDialogVisible.value = true;
};

const openEditAvailability = (record) => {
    editingAvailability.value = record;
    availabilityForm.clearErrors();
    availabilityForm.day_of_week = record.day_of_week;
    availabilityForm.is_available = !!record.is_available;
    availabilityForm.start_time = record.start_time ? record.start_time.slice(0, 5) : null;
    availabilityForm.end_time = record.end_time ? record.end_time.slice(0, 5) : null;
    startTimeModel.value = timeStringToDate(availabilityForm.start_time);
    endTimeModel.value = timeStringToDate(availabilityForm.end_time);
    availabilityDialogVisible.value = true;
};

const closeAvailabilityDialog = () => {
    availabilityDialogVisible.value = false;
    availabilityForm.clearErrors();
};

// When marked Unavailable, clear + disable the time fields (per spec).
watch(
    () => availabilityForm.is_available,
    (isAvailable) => {
        if (!isAvailable) {
            startTimeModel.value = null;
            endTimeModel.value = null;
            availabilityForm.start_time = null;
            availabilityForm.end_time = null;
        }
    },
);

const onSaveAvailability = () => {
    if (editingAvailability.value) {
        availabilityForm.put(
            route('scheduling.faculty.availability.update', [props.faculty.id, editingAvailability.value.id]),
            {
                preserveScroll: true,
                onSuccess: () => closeAvailabilityDialog(),
            },
        );
    } else {
        availabilityForm.post(route('scheduling.faculty.availability.store', props.faculty.id), {
            preserveScroll: true,
            onSuccess: () => closeAvailabilityDialog(),
        });
    }
};

const onDeleteAvailability = (record) => {
    Swal.fire({
        title: 'Delete this availability?',
        text: `${record.day_of_week}'s availability record will be removed.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('scheduling.faculty.availability.destroy', [props.faculty.id, record.id]), {
                preserveScroll: true,
            });
        }
    });
};

/* ------------------------------------------------------------------ */
/* Workload tab                                                        */
/* ------------------------------------------------------------------ */

const assignedUnits = computed(() =>
    (props.faculty.subjects ?? []).reduce((sum, subject) => sum + Number(subject.units || 0), 0),
);

const workloadPercent = computed(() => {
    const max = Number(props.faculty.max_teaching_units || 0);
    if (max <= 0) return 0;
    return Math.min(100, Math.round((assignedUnits.value / max) * 100));
});
</script>

<template>
    <Head :title="`${fullName} — Faculty Details`" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Faculty Details</span>
        </template>

        <div class="max-w-6xl mx-auto w-full">
            <!-- Back link -->
            <div class="mb-4">
                <Link :href="route('scheduling.faculty')" class="text-sm text-slate-500 hover:text-slate-700">
                    <i class="pi pi-arrow-left mr-1"></i> Back to Faculty
                </Link>
            </div>

            <!-- Page Title -->
            <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">{{ fullName }}</h1>
                    <p class="mt-1 text-slate-500">
                        {{ faculty.faculty_id }} &middot; {{ faculty.department?.name || '—' }} &middot;
                        {{ faculty.employment_type }}
                    </p>
                </div>
                <Tag
                    :value="faculty.status"
                    :severity="faculty.status === 'Active' ? 'success' : 'danger'"
                    class="!text-sm"
                />
            </div>

            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <Tabs v-model:value="activeTab">
                        <TabList>
                            <Tab value="information">Information</Tab>
                            <Tab value="qualifications">Teaching Qualifications</Tab>
                            <Tab value="availability">Availability</Tab>
                            <Tab value="workload">Workload</Tab>
                        </TabList>

                        <TabPanels>
                            <!-- INFORMATION -->
                            <TabPanel value="information">
                                <div class="flex justify-end mb-4">
                                    <Button label="Edit Information" icon="pi pi-pencil" severity="secondary" outlined @click="openEdit" />
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                                    <div>
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Faculty ID</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.faculty_id }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Full Name</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ fullName }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Employment Type</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.employment_type }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Faculty Category</p>
                                        <p class="mt-1">
                                            <Tag
                                                :value="faculty.faculty_category"
                                                :severity="faculty.faculty_category === 'General Education Faculty' ? 'warning' : 'info'"
                                            />
                                        </p>
                                    </div>
                                    <template v-if="faculty.faculty_category === 'Department Faculty'">
                                        <div>
                                            <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">College</p>
                                            <p class="mt-1 text-slate-800 font-medium">{{ faculty.college?.name || '—' }}</p>
                                        </div>
                                        <div>
                                            <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Department</p>
                                            <p class="mt-1 text-slate-800 font-medium">{{ faculty.department?.name || '—' }}</p>
                                        </div>
                                    </template>
                                    <div v-else class="sm:col-span-2 flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2.5">
                                        <i class="pi pi-info-circle text-amber-500 mt-0.5"></i>
                                        <p class="text-sm text-amber-700">
                                            This faculty member is not assigned to any specific college or department and
                                            may teach General Education subjects.
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Specialization</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.specialization || '—' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Max Teaching Units</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.max_teaching_units }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Email</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.email || '—' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Contact Number</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.contact_number || '—' }}</p>
                                    </div>
                                    <div class="sm:col-span-2 lg:col-span-3">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Remarks</p>
                                        <p class="mt-1 text-slate-800 font-medium whitespace-pre-line">{{ faculty.remarks || '—' }}</p>
                                    </div>
                                </div>
                            </TabPanel>

                            <!-- TEACHING QUALIFICATIONS -->
                            <TabPanel value="qualifications">
                                <label class="text-sm font-medium text-slate-700">Assign Subjects</label>
                                <MultiSelect
                                    v-model="selectedSubjectIds"
                                    :options="subjects"
                                    optionLabel="subject_code"
                                    optionValue="id"
                                    filter
                                    filterPlaceholder="Search subjects"
                                    display="chip"
                                    placeholder="Select subjects this faculty member can teach"
                                    class="w-full mt-1"
                                >
                                    <template #option="{ option }">
                                        <span class="font-medium">{{ option.subject_code }}</span>
                                        <span class="text-slate-400"> — {{ option.subject_title }}</span>
                                    </template>
                                </MultiSelect>

                                <div class="flex justify-end mt-3">
                                    <Button
                                        label="Save Qualifications"
                                        icon="pi pi-check"
                                        severity="success"
                                        :loading="savingQualifications"
                                        :disabled="!isQualificationsDirty"
                                        @click="saveQualifications"
                                    />
                                </div>

                                <DataTable :value="assignedSubjects" dataKey="id" class="rounded-xl overflow-hidden mt-4" stripedRows>
                                    <template #empty>
                                        <div class="text-center py-8">
                                            <p class="text-slate-500 font-medium text-sm">No subjects assigned yet.</p>
                                        </div>
                                    </template>
                                    <Column field="subject_code" header="Subject Code" style="width: 10rem" />
                                    <Column field="subject_title" header="Subject Title" />
                                    <Column header="Category" style="width: 11rem">
                                        <template #body="{ data }"><Tag :value="data.category" severity="info" /></template>
                                    </Column>
                                    <Column header="Units" style="width: 7rem">
                                        <template #body="{ data }">{{ data.units }}</template>
                                    </Column>
                                    <Column header="Remove" style="width: 7rem">
                                        <template #body="{ data }">
                                            <Button
                                                icon="pi pi-trash"
                                                text
                                                rounded
                                                severity="danger"
                                                size="small"
                                                aria-label="Remove"
                                                @click="removeSubject(data.id)"
                                            />
                                        </template>
                                    </Column>
                                </DataTable>

                                <p v-if="isQualificationsDirty" class="text-xs text-amber-600 mt-2">
                                    You have unsaved changes — click "Save Qualifications" to apply them.
                                </p>
                            </TabPanel>

                            <!-- AVAILABILITY -->
                            <TabPanel value="availability">
                                <!-- Weekly grid -->
                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                                    <div
                                        v-for="day in weekDays"
                                        :key="day"
                                        class="rounded-xl border border-slate-100 p-3"
                                        :class="availabilityByDay[day]?.is_available ? 'bg-emerald-50/50' : 'bg-slate-50'"
                                    >
                                        <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ day }}</p>
                                        <template v-if="availabilityByDay[day]?.is_available">
                                            <p class="mt-2 text-sm font-medium text-slate-800">
                                                {{ formatTime(availabilityByDay[day].start_time) }}
                                            </p>
                                            <p class="text-xs text-slate-400">to</p>
                                            <p class="text-sm font-medium text-slate-800">
                                                {{ formatTime(availabilityByDay[day].end_time) }}
                                            </p>
                                        </template>
                                        <p v-else class="mt-2 text-sm font-medium text-slate-400">
                                            {{ availabilityByDay[day] ? 'Unavailable' : 'Not set' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="flex justify-end mb-3">
                                    <Button
                                        label="Add Availability"
                                        icon="pi pi-plus"
                                        severity="success"
                                        :disabled="availableDayOptions.length === 0"
                                        @click="openAddAvailability"
                                    />
                                </div>

                                <DataTable :value="faculty.availabilities ?? []" dataKey="id" class="rounded-xl overflow-hidden" stripedRows>
                                    <template #empty>
                                        <div class="text-center py-8">
                                            <p class="text-slate-500 font-medium text-sm">No availability records yet.</p>
                                        </div>
                                    </template>
                                    <Column field="day_of_week" header="Day" style="width: 10rem" />
                                    <Column header="Available" style="width: 9rem">
                                        <template #body="{ data }">
                                            <Tag
                                                :value="data.is_available ? 'Available' : 'Unavailable'"
                                                :severity="data.is_available ? 'success' : 'danger'"
                                            />
                                        </template>
                                    </Column>
                                    <Column header="Start" style="width: 9rem">
                                        <template #body="{ data }">{{ formatTime(data.start_time) }}</template>
                                    </Column>
                                    <Column header="End" style="width: 9rem">
                                        <template #body="{ data }">{{ formatTime(data.end_time) }}</template>
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
                                                    @click="openEditAvailability(data)"
                                                />
                                                <Button
                                                    icon="pi pi-trash"
                                                    text
                                                    rounded
                                                    severity="danger"
                                                    size="small"
                                                    aria-label="Delete"
                                                    @click="onDeleteAvailability(data)"
                                                />
                                            </div>
                                        </template>
                                    </Column>
                                </DataTable>

                                <p class="text-xs text-slate-400 mt-3">
                                    <i class="pi pi-info-circle mr-1"></i>
                                    The scheduling engine will never assign this faculty member outside their available hours.
                                </p>
                            </TabPanel>

                            <!-- WORKLOAD -->
                            <TabPanel value="workload">
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                                    <div class="rounded-xl border border-slate-100 p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Max Teaching Units</p>
                                        <p class="mt-1 text-2xl font-bold text-[#1E293B]">{{ faculty.max_teaching_units }}</p>
                                    </div>
                                    <div class="rounded-xl border border-slate-100 p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Assigned Units (Qualified)</p>
                                        <p class="mt-1 text-2xl font-bold text-[#1E293B]">{{ assignedUnits }}</p>
                                    </div>
                                    <div class="rounded-xl border border-slate-100 p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Load Utilization</p>
                                        <p class="mt-1 text-2xl font-bold text-[#1E293B]">{{ workloadPercent }}%</p>
                                    </div>
                                </div>
                                <p class="text-xs text-slate-400">
                                    <i class="pi pi-info-circle mr-1"></i>
                                    This reflects the units of subjects this faculty member is qualified to teach, not their
                                    actual scheduled load. Actual teaching load will be available once schedules are finalized
                                    by the scheduling engine.
                                </p>
                            </TabPanel>
                        </TabPanels>
                    </Tabs>
                </template>
            </Card>
        </div>

        <!-- Edit Faculty Information Dialog -->
        <Dialog
            v-model:visible="editVisible"
            modal
            header="Edit Faculty"
            :style="{ width: '760px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            @hide="closeEdit"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4" @submit.prevent="onSaveFaculty">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Faculty ID <span class="text-red-500">*</span></label>
                    <InputText v-model="facultyForm.faculty_id" :invalid="!!facultyForm.errors.faculty_id" class="w-full" />
                    <small v-if="facultyForm.errors.faculty_id" class="text-red-500">{{ facultyForm.errors.faculty_id }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Employment Type <span class="text-red-500">*</span></label>
                    <Select
                        v-model="facultyForm.employment_type"
                        :options="employmentTypeOptions"
                        placeholder="Select employment type"
                        :invalid="!!facultyForm.errors.employment_type"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.employment_type" class="text-red-500">{{ facultyForm.errors.employment_type }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Faculty Category <span class="text-red-500">*</span></label>
                    <Select
                        v-model="facultyForm.faculty_category"
                        :options="facultyCategoryOptions"
                        placeholder="Select faculty category"
                        :invalid="!!facultyForm.errors.faculty_category"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.faculty_category" class="text-red-500">{{ facultyForm.errors.faculty_category }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">First Name <span class="text-red-500">*</span></label>
                    <InputText v-model="facultyForm.first_name" :invalid="!!facultyForm.errors.first_name" class="w-full" />
                    <small v-if="facultyForm.errors.first_name" class="text-red-500">{{ facultyForm.errors.first_name }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Middle Name</label>
                    <InputText v-model="facultyForm.middle_name" :invalid="!!facultyForm.errors.middle_name" class="w-full" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Last Name <span class="text-red-500">*</span></label>
                    <InputText v-model="facultyForm.last_name" :invalid="!!facultyForm.errors.last_name" class="w-full" />
                    <small v-if="facultyForm.errors.last_name" class="text-red-500">{{ facultyForm.errors.last_name }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Suffix</label>
                    <InputText v-model="facultyForm.suffix" :invalid="!!facultyForm.errors.suffix" class="w-full" />
                </div>

                <div v-if="isDepartmentFaculty" class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">College <span class="text-red-500">*</span></label>
                    <Select
                        v-model="facultyForm.college_id"
                        :options="colleges"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Select a college"
                        showClear
                        :invalid="!!facultyForm.errors.college_id"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.college_id" class="text-red-500">{{ facultyForm.errors.college_id }}</small>
                </div>

                <div v-if="isDepartmentFaculty" class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Department <span class="text-red-500">*</span></label>
                    <Select
                        v-model="facultyForm.department_id"
                        :options="filteredDepartments"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Select a department"
                        showClear
                        :disabled="!facultyForm.college_id"
                        :invalid="!!facultyForm.errors.department_id"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.department_id" class="text-red-500">{{ facultyForm.errors.department_id }}</small>
                </div>

                <div v-if="!isDepartmentFaculty" class="sm:col-span-2 flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2.5">
                    <i class="pi pi-info-circle text-amber-500 mt-0.5"></i>
                    <p class="text-sm text-amber-700">
                        This faculty member is not assigned to any specific college or department and may teach
                        General Education subjects.
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Specialization</label>
                    <InputText v-model="facultyForm.specialization" :invalid="!!facultyForm.errors.specialization" class="w-full" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Maximum Teaching Units <span class="text-red-500">*</span></label>
                    <InputNumber
                        v-model="facultyForm.max_teaching_units"
                        :min="0"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!facultyForm.errors.max_teaching_units"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="facultyForm.errors.max_teaching_units" class="text-red-500">{{ facultyForm.errors.max_teaching_units }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Status <span class="text-red-500">*</span></label>
                    <Select
                        v-model="facultyForm.status"
                        :options="statusOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select status"
                        :invalid="!!facultyForm.errors.status"
                        class="w-full"
                    />
                    <small v-if="facultyForm.errors.status" class="text-red-500">{{ facultyForm.errors.status }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Email</label>
                    <InputText v-model="facultyForm.email" type="email" :invalid="!!facultyForm.errors.email" class="w-full" />
                    <small v-if="facultyForm.errors.email" class="text-red-500">{{ facultyForm.errors.email }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Contact Number</label>
                    <InputText v-model="facultyForm.contact_number" :invalid="!!facultyForm.errors.contact_number" class="w-full" />
                </div>

                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label class="text-sm font-medium text-slate-700">Remarks</label>
                    <Textarea v-model="facultyForm.remarks" autoResize rows="3" :invalid="!!facultyForm.errors.remarks" class="w-full" />
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeEdit" />
                <Button label="Save Changes" icon="pi pi-check" severity="success" :loading="facultyForm.processing" @click="onSaveFaculty" />
            </template>
        </Dialog>

        <!-- Add / Edit Availability Dialog -->
        <Dialog
            v-model:visible="availabilityDialogVisible"
            modal
            :header="editingAvailability ? 'Edit Availability' : 'Add Availability'"
            :style="{ width: '480px' }"
            :breakpoints="{ '640px': '95vw' }"
            :draggable="false"
            @hide="closeAvailabilityDialog"
        >
            <form class="grid grid-cols-1 gap-4" @submit.prevent="onSaveAvailability">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Day <span class="text-red-500">*</span></label>
                    <Select
                        v-model="availabilityForm.day_of_week"
                        :options="editingAvailability ? allDayOptions : availableDayOptions"
                        placeholder="Select a day"
                        :disabled="!!editingAvailability"
                        :invalid="!!availabilityForm.errors.day_of_week"
                        class="w-full"
                    />
                    <small v-if="availabilityForm.errors.day_of_week" class="text-red-500">{{ availabilityForm.errors.day_of_week }}</small>
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox v-model="availabilityForm.is_available" binary inputId="is_available" />
                    <label for="is_available" class="text-sm font-medium text-slate-700">Available</label>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">
                        Start Time <span v-if="availabilityForm.is_available" class="text-red-500">*</span>
                    </label>
                    <DatePicker
                        v-model="startTimeModel"
                        timeOnly
                        hourFormat="12"
                        placeholder="Select start time"
                        :disabled="!availabilityForm.is_available"
                        :invalid="!!availabilityForm.errors.start_time"
                        class="w-full"
                    />
                    <small v-if="availabilityForm.errors.start_time" class="text-red-500">{{ availabilityForm.errors.start_time }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">
                        End Time <span v-if="availabilityForm.is_available" class="text-red-500">*</span>
                    </label>
                    <DatePicker
                        v-model="endTimeModel"
                        timeOnly
                        hourFormat="12"
                        placeholder="Select end time"
                        :disabled="!availabilityForm.is_available"
                        :invalid="!!availabilityForm.errors.end_time"
                        class="w-full"
                    />
                    <small v-if="availabilityForm.errors.end_time" class="text-red-500">{{ availabilityForm.errors.end_time }}</small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeAvailabilityDialog" />
                <Button
                    label="Save"
                    icon="pi pi-check"
                    severity="success"
                    :loading="availabilityForm.processing"
                    @click="onSaveAvailability"
                />
            </template>
        </Dialog>
    </AppLayout>
</template>