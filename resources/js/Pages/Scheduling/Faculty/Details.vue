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
import ProgressBar from 'primevue/progressbar';
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    faculty: { type: Object, required: true },
    colleges: { type: Array, default: () => [] },
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
const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

const editVisible = ref(false);

const facultyForm = useForm({
    faculty_id: props.faculty.faculty_id,
    first_name: props.faculty.first_name,
    middle_name: props.faculty.middle_name,
    last_name: props.faculty.last_name,
    suffix: props.faculty.suffix,
    employment_type: props.faculty.employment_type,
    college_id: props.faculty.college_id,
    max_teaching_units: props.faculty.max_teaching_units,
    workload_type: props.faculty.workload_type ?? 'units',
    max_weekly_hours: props.faculty.max_weekly_hours,
    status: props.faculty.status,
    email: props.faculty.email,
    contact_number: props.faculty.contact_number,
    remarks: props.faculty.remarks,
});

// Whichever workload measurement the institution uses — 'units'
// (default, checked against Max Teaching Units) or 'hours' (checked
// against Max Weekly Hours instead). See FacultyWorkloadService.
const workloadTypeOptions = [
    { label: 'Teaching Units', value: 'units' },
    { label: 'Weekly Hours', value: 'hours' },
];

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

const openEdit = () => {
    facultyForm.clearErrors();
    facultyForm.faculty_id = props.faculty.faculty_id;
    facultyForm.first_name = props.faculty.first_name;
    facultyForm.middle_name = props.faculty.middle_name;
    facultyForm.last_name = props.faculty.last_name;
    facultyForm.suffix = props.faculty.suffix;
    facultyForm.employment_type = props.faculty.employment_type;
    facultyForm.college_id = props.faculty.college_id;
    facultyForm.max_teaching_units = props.faculty.max_teaching_units;
    facultyForm.workload_type = props.faculty.workload_type ?? 'units';
    facultyForm.max_weekly_hours = props.faculty.max_weekly_hours;
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
/* Workload tab — FACULTY WORKLOAD VALIDATION SYSTEM.                  */
/* `faculty.workload` is computed server-side by FacultyWorkloadService */
/* (FacultyController@show) — the same engine Auto Generate/Recommend  */
/* Faculty/Manual Assignment/Save Schedule all use — so this tab can    */
/* never disagree with what the scheduling engine actually enforces.   */
/* ------------------------------------------------------------------ */

const workload = computed(() => props.faculty.workload ?? null);

const workloadPercent = computed(() => {
    if (!workload.value) return 0;
    return Math.min(100, Math.max(0, workload.value.percent ?? 0));
});

const workloadStatusMeta = computed(() => {
    switch (workload.value?.status) {
        case 'overloaded':
            return { emoji: '🔴', label: 'Overloaded', class: 'text-red-600 bg-red-50 border-red-200' };
        case 'high':
            return { emoji: '🟡', label: 'Approaching Limit', class: 'text-amber-600 bg-amber-50 border-amber-200' };
        default:
            return { emoji: '🟢', label: 'Healthy', class: 'text-emerald-600 bg-emerald-50 border-emerald-200' };
    }
});

// Kept for the "qualified subjects" reference figure still shown
// beneath the main workload cards (how many units this faculty member
// is qualified to teach in total, regardless of whether scheduled).
const qualifiedUnits = computed(() =>
    (props.faculty.subjects ?? []).reduce((sum, subject) => sum + Number(subject.units || 0), 0),
);

// The actual Subjects/Sections making up the "Current Load" number
// above — same 'Scheduled'/'Draft', active-semester placements,
// straight from FacultyWorkloadService::assignedPlacements() via
// FacultyController@show (evaluate(..., includePlacements: true)).
const assignedPlacements = computed(() => workload.value?.assigned_placements ?? []);

// Reuses the formatTime(time) declared above (Availability tab) —
// same 'HH:mm'/'HH:mm:ss' -> 12-hour am/pm formatting applies here.

const placementStatusSeverity = (status) => {
    switch (status) {
        case 'Scheduled':
            return 'success';
        case 'Draft':
            return 'warn';
        default:
            return 'secondary';
    }
};
</script>

<template>
    <Head :title="`${fullName} — Faculty Details`" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Faculty Details</span>
        </template>

        <div class="max-w-6xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Back link -->
            <div class="mb-4">
                <Link :href="route('scheduling.faculty')" class="text-sm text-slate-500 hover:text-slate-700">
                    <i class="pi pi-arrow-left mr-1"></i> Back to Faculty
                </Link>
            </div>

            <!-- Page Title -->
            <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2 text-[#1E293B]">
                        {{ fullName }}
                        <InfoPopover
                            title="Faculty Details"
                            :paragraphs="[
                                'Manage this faculty member\'s profile, teaching qualifications, weekly availability, and current teaching workload.',
                            ]"
                            :bullets="[
                                'Teaching Qualifications control which subjects this faculty member can be assigned to when scheduling.',
                                'Availability limits which days/times the scheduling engine may assign this faculty member — it will never schedule outside these hours.',
                                'Workload shows units currently assigned against the Max Teaching Units cap set on the Information tab.',
                            ]"
                        />
                    </h1>
                    <p class="mt-1 text-slate-500">
                        {{ faculty.faculty_id }} &middot; {{ faculty.college?.name || '—' }} &middot;
                        {{ faculty.employment_type }}
                    </p>
                </div>
                <Tag
                    :value="faculty.status"
                    :severity="faculty.status === 'Active' ? 'success' : 'danger'"
                    class="!text-sm"
                />
            </div>

            <div class="neu-card rounded-2xl transition-colors duration-300">
            <Card
                class="!rounded-2xl !bg-transparent !border-0 !shadow-none"
                :pt="{ body: { class: '!bg-transparent' } }"
            >
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

                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Faculty ID</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.faculty_id }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Full Name</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ fullName }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Employment Type</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.employment_type }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">College</p>
                                        <p class="mt-1">
                                            <span v-if="faculty.college?.name" class="text-slate-800 font-medium">{{ faculty.college.name }}</span>
                                            <Tag v-else value="General Education" severity="warning" />
                                        </p>
                                    </div>
                                    <div v-if="!faculty.college?.name" class="sm:col-span-2 flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2.5">
                                        <i class="pi pi-info-circle text-amber-500 mt-0.5"></i>
                                        <p class="text-sm text-amber-700">
                                            This faculty member is not assigned to any specific college and
                                            may teach General Education subjects.
                                        </p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Max Teaching Units</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.max_teaching_units }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Email</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.email || '—' }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Contact Number</p>
                                        <p class="mt-1 text-slate-800 font-medium">{{ faculty.contact_number || '—' }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4 sm:col-span-2 lg:col-span-3">
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
                                    class="w-full mt-1 neu-inset !border-none"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
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

                                <DataTable
                                    :value="assignedSubjects"
                                    dataKey="id"
                                    class="neu-inset neu-table rounded-xl overflow-hidden mt-4"
                                    :class="isDark ? 'neu-table-dark' : ''"
                                    stripedRows
                                >
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
                                        class="neu-inset rounded-xl p-3"
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

                                <DataTable
                                    :value="faculty.availabilities ?? []"
                                    dataKey="id"
                                    class="neu-inset neu-table rounded-xl overflow-hidden"
                                    :class="isDark ? 'neu-table-dark' : ''"
                                    stripedRows
                                >
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
                                <div
                                    class="mb-6 flex items-center justify-between rounded-xl p-4 neu-inset"
                                    :class="workloadStatusMeta.class"
                                >
                                    <div>
                                        <p class="text-xs font-semibold tracking-wide uppercase opacity-70">Current Load</p>
                                        <p class="mt-1 text-2xl font-bold">
                                            {{ workload?.current ?? 0 }} / {{ workload?.max ?? 0 }} {{ workload?.unit_label ?? 'Units' }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-3xl leading-none">{{ workloadStatusMeta.emoji }}</p>
                                        <p class="mt-1 text-sm font-semibold">{{ workloadStatusMeta.label }} — {{ workloadPercent }}%</p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Maximum Load</p>
                                        <p class="mt-1 text-2xl font-bold text-[#1E293B]">{{ workload?.max ?? 0 }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Current Assigned</p>
                                        <p class="mt-1 text-2xl font-bold text-[#1E293B]">{{ workload?.current ?? 0 }}</p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Remaining</p>
                                        <p
                                            class="mt-1 text-2xl font-bold"
                                            :class="(workload?.remaining ?? 0) < 0 ? 'text-red-600' : 'text-[#1E293B]'"
                                        >
                                            {{ workload?.remaining ?? 0 }}
                                        </p>
                                    </div>
                                    <div class="neu-inset rounded-xl p-4">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">Assigned Subjects</p>
                                        <p class="mt-1 text-2xl font-bold text-[#1E293B]">{{ workload?.assigned_subjects ?? 0 }}</p>
                                    </div>
                                </div>

                                <ProgressBar
                                    :value="workloadPercent"
                                    :showValue="false"
                                    class="mb-6 h-2"
                                    :pt="{
                                        value: {
                                            class:
                                                workload?.status === 'overloaded'
                                                    ? '!bg-red-500'
                                                    : workload?.status === 'high'
                                                      ? '!bg-amber-500'
                                                      : '!bg-emerald-500',
                                        },
                                    }"
                                />

                                <p class="text-xs text-slate-400">
                                    <i class="pi pi-info-circle mr-1"></i>
                                    Current Load reflects every 'Scheduled' or 'Draft' subject this faculty member is actually
                                    assigned to teach in the active semester (Auto Generate + manually saved schedules alike),
                                    measured in {{ workload?.unit_label ?? 'Units' }} — the same figure Auto Generate Schedule,
                                    Recommend Faculty, Manual Assignment, and Save Schedule all validate against. Reference:
                                    qualified to teach {{ qualifiedUnits }} unit(s) worth of subjects in total (Teaching
                                    Qualifications tab).
                                </p>

                                <!-- Assigned Subjects list — what actually makes up Current Load -->
                                <div class="mt-8">
                                    <h3 class="mb-3 text-sm font-semibold tracking-wide text-slate-500 uppercase">
                                        Assigned Subjects — {{ assignedPlacements.length }}
                                    </h3>

                                    <DataTable
                                        v-if="assignedPlacements.length"
                                        :value="assignedPlacements"
                                        dataKey="id"
                                        class="text-sm neu-inset neu-table rounded-xl overflow-hidden"
                                        :class="isDark ? 'neu-table-dark' : ''"
                                        stripedRows
                                    >
                                        <Column field="edp_code" header="EDP Code">
                                            <template #body="{ data }">
                                                <span class="font-mono text-xs text-slate-600">{{ data.edp_code || '—' }}</span>
                                            </template>
                                        </Column>
                                        <Column field="subject_code" header="Subject">
                                            <template #body="{ data }">
                                                <div class="font-semibold text-[#1E293B]">{{ data.subject_code }}</div>
                                                <div class="text-xs text-slate-500">{{ data.subject_title }}</div>
                                            </template>
                                        </Column>
                                        <Column field="section_code" header="Section">
                                            <template #body="{ data }">{{ data.section_code || '—' }}</template>
                                        </Column>
                                        <Column header="Schedule">
                                            <template #body="{ data }">
                                                <span v-if="data.days">
                                                    {{ data.days }} &middot; {{ formatTime(data.start_time) }}–{{ formatTime(data.end_time) }}
                                                </span>
                                                <span v-else class="text-slate-400">Not yet scheduled</span>
                                            </template>
                                        </Column>
                                        <Column field="room_name" header="Room">
                                            <template #body="{ data }">{{ data.room_name || '—' }}</template>
                                        </Column>
                                        <Column header="Load">
                                            <template #body="{ data }">{{ data.load }} {{ workload?.unit_label ?? 'Units' }}</template>
                                        </Column>
                                        <Column field="status" header="Status">
                                            <template #body="{ data }">
                                                <Tag :value="data.status" :severity="placementStatusSeverity(data.status)" />
                                            </template>
                                        </Column>
                                    </DataTable>

                                    <div
                                        v-else
                                        class="rounded-xl border border-dashed border-slate-200 p-6 text-center text-sm text-slate-400"
                                    >
                                        No subjects assigned to this faculty member in the active semester yet.
                                    </div>
                                </div>
                            </TabPanel>
                        </TabPanels>
                    </Tabs>
                </template>
            </Card>
            </div>
        </div>

        <!-- Edit Faculty Information Dialog -->
        <Dialog
            v-model:visible="editVisible"
            modal
            header="Edit Faculty"
            :style="{ width: '760px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
            @hide="closeEdit"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4 neu-form" @submit.prevent="onSaveFaculty">
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
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="facultyForm.errors.employment_type" class="text-red-500">{{ facultyForm.errors.employment_type }}</small>
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

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">College</label>
                    <Select
                        v-model="facultyForm.college_id"
                        :options="colleges"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Leave blank for General Education"
                        showClear
                        :invalid="!!facultyForm.errors.college_id"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="facultyForm.errors.college_id" class="text-red-500">{{ facultyForm.errors.college_id }}</small>
                    <p v-else class="text-xs text-slate-400">
                        Leave blank if this faculty member is General Education (no department).
                    </p>
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
                    <label class="text-sm font-medium text-slate-700">Workload Measurement</label>
                    <Select
                        v-model="facultyForm.workload_type"
                        :options="workloadTypeOptions"
                        optionLabel="label"
                        optionValue="value"
                        :invalid="!!facultyForm.errors.workload_type"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <p class="text-xs text-slate-400">Whichever the institution uses to cap this faculty member's load.</p>
                </div>

                <div v-if="facultyForm.workload_type === 'hours'" class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Maximum Weekly Hours</label>
                    <InputNumber
                        v-model="facultyForm.max_weekly_hours"
                        :min="0"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!facultyForm.errors.max_weekly_hours"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="facultyForm.errors.max_weekly_hours" class="text-red-500">{{ facultyForm.errors.max_weekly_hours }}</small>
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
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
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
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
            @hide="closeAvailabilityDialog"
        >
            <form class="grid grid-cols-1 gap-4 neu-form" @submit.prevent="onSaveAvailability">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-slate-700">Day <span class="text-red-500">*</span></label>
                    <Select
                        v-model="availabilityForm.day_of_week"
                        :options="editingAvailability ? allDayOptions : availableDayOptions"
                        placeholder="Select a day"
                        :disabled="!!editingAvailability"
                        :invalid="!!availabilityForm.errors.day_of_week"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
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
                        :pt="{ panel: { class: isDark ? 'dark-scope' : '' } }"
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
                        :pt="{ panel: { class: isDark ? 'dark-scope' : '' } }"
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

<style scoped>
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (added conditionally via isDark) recolors the shared light-mode
   Tailwind utility classes and PrimeVue component chrome used
   throughout this page. Dialogs and overlay panels (Select/MultiSelect/
   DatePicker) are teleported to <body> by PrimeVue, so their rules use
   :global() with a compound selector instead of :deep() — Vue's
   scoped-CSS attribute doesn't reliably travel through the teleport
   boundary. */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }

.dark-scope :deep(.p-card) { background: #101A35 !important; color: #F8FAFC; border: 1px solid rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tabpanels) { background: transparent !important; color: #F1F5F9 !important; padding: 0 !important; }
.dark-scope :deep(.p-tabpanel) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-textarea),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect),
.dark-scope :deep(.p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-inputtext::placeholder),
.dark-scope :deep(.p-textarea::placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }
.dark-scope :deep(.p-multiselect-label.p-placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-inputnumber-button) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.15) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-checkbox .p-checkbox-box) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.25) !important; }
.dark-scope :deep(.p-progressbar) { background: rgba(255, 255, 255, 0.08) !important; }

.dark-scope :deep(.p-datatable) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.06) !important; color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.12) !important; font-weight: 600; }
.dark-scope :deep(.p-datatable-tbody > tr) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-tbody > tr > td) { color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover) { background: rgba(255, 255, 255, 0.07) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover > td) { background: transparent !important; }
.dark-scope :deep(.p-datatable-emptymessage) { color: #CBD5E1 !important; }

.dark-scope :deep(.p-button-text.p-button-secondary) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-button-text.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-button-text.p-button-danger) { color: #FCA5A5 !important; }
.dark-scope :deep(.p-button-text.p-button-danger:hover) { background: rgba(248, 113, 113, 0.12) !important; color: #FECACA !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary) { color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.2) !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; }

/* Amber "General Education" info banner (Information tab) */
.dark-scope :deep(.bg-amber-50) { background: rgba(245, 158, 11, 0.12) !important; }
.dark-scope :deep(.border-amber-200) { border-color: rgba(245, 158, 11, 0.3) !important; }
.dark-scope :deep(.text-amber-700),
.dark-scope :deep(.text-amber-800) { color: #FCD34D !important; }

/* Dialogs (teleported to <body>) */
:global(.dark-scope.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-title) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }

:global(.dark-scope.p-dialog label) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog p.text-xs.text-slate-400) { color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .p-inputtext),
:global(.dark-scope.p-dialog .p-textarea),
:global(.dark-scope.p-dialog .p-select),
:global(.dark-scope.p-dialog .p-multiselect),
:global(.dark-scope.p-dialog .p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.18) !important;
    color: #F8FAFC !important;
}
:global(.dark-scope.p-dialog .p-inputtext::placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-select-label),
:global(.dark-scope.p-dialog .p-multiselect-label) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-select-label.p-placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-inputnumber-button) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.18) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-checkbox .p-checkbox-box) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.3) !important; }

/* Select / MultiSelect dropdown overlays + DatePicker time panel
   (also teleported) */
:global(.p-select-overlay.dark-scope),
:global(.p-multiselect-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-select-overlay.dark-scope .p-select-option),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option) { color: #F1F5F9 !important; }
:global(.p-select-overlay.dark-scope .p-select-option:hover),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
:global(.p-select-overlay.dark-scope .p-select-filter),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-filter) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.15) !important; color: #F8FAFC !important; }

:global(.p-datepicker-panel.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-datepicker-panel.dark-scope .p-datepicker-time-picker) { border-color: rgba(255, 255, 255, 0.1) !important; }
:global(.p-datepicker-panel.dark-scope .p-datepicker-time-picker span) { color: #F8FAFC !important; }
:global(.p-datepicker-panel.dark-scope .p-button-text) { color: #CBD5E1 !important; }
</style>