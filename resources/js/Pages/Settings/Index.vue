<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Password from 'primevue/password';
import Button from 'primevue/button';
import Divider from 'primevue/divider';
import FloatLabel from 'primevue/floatlabel';
import Toast from 'primevue/toast';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import ToggleSwitch from 'primevue/toggleswitch';
import Select from 'primevue/select';
import Checkbox from 'primevue/checkbox';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    visibleGroups: { type: Array, default: () => [] },
    editableGroups: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    schoolYear: { type: Object, default: null },
    system: { type: Object, default: null },
});

const toast = useToast();
const page = usePage();

watch(
    () => page.props.flash?.success,
    (message) => { if (message) toast.add({ severity: 'success', summary: 'Success', detail: message, life: 4000 }); },
);
watch(
    () => page.props.flash?.error,
    (message) => { if (message) toast.add({ severity: 'error', summary: 'Error', detail: message, life: 4000 }); },
);

const authRoles = computed(() => page.props.auth?.roles ?? []);
const isAdministrator = computed(() => authRoles.value.includes('Administrator'));
const activeTab = ref(props.visibleGroups[0] ?? 'general');

const canEdit = (group) => props.editableGroups.includes(group);
const has = (group) => props.visibleGroups.includes(group);

const onError = () => {
    toast.add({ severity: 'warn', summary: 'Missing information', detail: 'Please check the highlighted fields and try again.', life: 3000 });
};

/* ------------------------------------------------------------------ */
/* General                                                             */
/* ------------------------------------------------------------------ */
const logoFile = ref(null);
const generalForm = useForm({
    school_name: props.settings['general.school_name'] ?? '',
    school_short_name: props.settings['general.school_short_name'] ?? '',
    school_address: props.settings['general.school_address'] ?? '',
    school_contact: props.settings['general.school_contact'] ?? '',
    school_email: props.settings['general.school_email'] ?? '',
    logo: null,
});
const onLogoChange = (e) => {
    const file = e.target.files?.[0] ?? null;
    logoFile.value = file;
    generalForm.logo = file;
};
const saveGeneral = () => {
    generalForm.transform((data) => ({ ...data, _method: 'put' })).post(route('settings.general.update'), {
        preserveScroll: true,
        forceFormData: true,
        onError,
    });
};

/* ------------------------------------------------------------------ */
/* Academic defaults                                                   */
/* ------------------------------------------------------------------ */
const semesterOptions = ['1st Semester', '2nd Semester', 'Summer'];
const academicForm = useForm({
    default_academic_year: props.settings['academic.default_academic_year'] ?? '',
    default_semester: props.settings['academic.default_semester'] ?? '1st Semester',
});
const saveAcademic = () => {
    academicForm.transform((data) => ({ ...data, _method: 'put' })).post(route('settings.academic.update'), { preserveScroll: true, onError });
};

/* ------------------------------------------------------------------ */
/* Meeting frequency                                                   */
/* ------------------------------------------------------------------ */
const meetingForm = useForm({
    allow_1x: props.settings['meeting.allow_1x'] ?? true,
    allow_2x: props.settings['meeting.allow_2x'] ?? true,
    allow_3x: props.settings['meeting.allow_3x'] ?? false,
});
const saveMeeting = () => {
    meetingForm.transform((data) => ({ ...data, _method: 'put' })).post(route('settings.meeting.update'), { preserveScroll: true, onError });
};

/* ------------------------------------------------------------------ */
/* Faculty & workload                                                  */
/* ------------------------------------------------------------------ */
const workloadForm = useForm({
    max_teaching_load: props.settings['workload.max_teaching_load'] ?? 24,
    warning_threshold: props.settings['workload.warning_threshold'] ?? 85,
    overloaded_threshold: props.settings['workload.overloaded_threshold'] ?? 100,
    allow_admin_override: props.settings['workload.allow_admin_override'] ?? true,
});
const saveWorkload = () => {
    workloadForm.transform((data) => ({ ...data, _method: 'put' })).post(route('settings.workload.update'), { preserveScroll: true, onError });
};

/* ------------------------------------------------------------------ */
/* Rooms                                                                */
/* ------------------------------------------------------------------ */
const roomPriorityLabels = {
    subject_requirement: 'Subject Requirement',
    college: 'College',
    department_program: 'Department / Program',
    capacity: 'Capacity',
    availability: 'Availability',
};
const roomsForm = useForm({
    enable_recommendations: props.settings['rooms.enable_recommendations'] ?? true,
    priority_order: [...(props.settings['rooms.priority_order'] ?? ['subject_requirement', 'college', 'department_program', 'capacity', 'availability'])],
});
const moveRoomPriority = (index, direction) => {
    const target = index + direction;
    if (target < 0 || target >= roomsForm.priority_order.length) return;
    const arr = [...roomsForm.priority_order];
    [arr[index], arr[target]] = [arr[target], arr[index]];
    roomsForm.priority_order = arr;
};
const saveRooms = () => {
    roomsForm.transform((data) => ({ ...data, _method: 'put' })).post(route('settings.rooms.update'), { preserveScroll: true, onError });
};

/* ------------------------------------------------------------------ */
/* Auto Schedule / AI                                                  */
/* ------------------------------------------------------------------ */
const autoModeOptions = [
    { label: 'Balanced', value: 'balanced' },
    { label: 'Constraint Priority', value: 'constraint_priority' },
    { label: 'Optimization Priority', value: 'optimization_priority' },
];
const priorityLevels = [
    { label: 'High', value: 'high' },
    { label: 'Medium', value: 'medium' },
    { label: 'Low', value: 'low' },
];
const priorityLabels = {
    faculty_availability: 'Faculty Availability',
    room_availability: 'Room Availability',
    faculty_workload: 'Faculty Workload',
    section_daily_load: 'Section Daily Load',
    minimize_idle_gaps: 'Minimize Idle Gaps',
    room_suitability: 'Room Suitability',
    preferred_meeting_frequency: 'Preferred Meeting Frequency',
    merge_irregular_classes: 'Merge Irregular Classes',
    college_program_room_restrictions: 'College/Program Room Restrictions',
};
const autoScheduleForm = useForm({
    mode: props.settings['autoschedule.mode'] ?? 'balanced',
    priorities: { ...(props.settings['autoschedule.priorities'] ?? {}) },
    enable_daily_load_optimization: props.settings['autoschedule.enable_daily_load_optimization'] ?? true,
    max_continuous_duration_hours: props.settings['autoschedule.max_continuous_duration_hours'] ?? 5,
});
const saveAutoSchedule = () => {
    autoScheduleForm.transform((data) => ({ ...data, _method: 'put' })).post(route('settings.autoschedule.update'), { preserveScroll: true, onError });
};

/* ------------------------------------------------------------------ */
/* Irregular sections                                                  */
/* ------------------------------------------------------------------ */
const irregularModeOptions = [
    { label: 'Auto-select', value: 'auto_select' },
    { label: 'Recommend Merge', value: 'recommend_merge' },
    { label: 'Independent Class', value: 'independent_class' },
];
const irregularForm = useForm({
    default_estimated_students: props.settings['irregular.default_estimated_students'] ?? 5,
    enable_merge_recommendations: props.settings['irregular.enable_merge_recommendations'] ?? true,
    default_mode: props.settings['irregular.default_mode'] ?? 'auto_select',
});
const saveIrregular = () => {
    irregularForm.transform((data) => ({ ...data, _method: 'put' })).post(route('settings.irregular.update'), { preserveScroll: true, onError });
};

/* ------------------------------------------------------------------ */
/* Notifications                                                       */
/* ------------------------------------------------------------------ */
const notificationsForm = useForm({
    schedule_conflict: props.settings['notifications.schedule_conflict'] ?? true,
    workload_warning: props.settings['notifications.workload_warning'] ?? true,
    room_conflict: props.settings['notifications.room_conflict'] ?? true,
    unscheduled_subject: props.settings['notifications.unscheduled_subject'] ?? true,
    merge_recommendation: props.settings['notifications.merge_recommendation'] ?? true,
});
const saveNotifications = () => {
    notificationsForm.transform((data) => ({ ...data, _method: 'put' })).post(route('settings.notifications.update'), { preserveScroll: true, onError });
};

/* ------------------------------------------------------------------ */
/* System / Maintenance                                                */
/* ------------------------------------------------------------------ */
const refreshingCache = ref(false);
const refreshCache = () => {
    refreshingCache.value = true;
    router.post(route('settings.system.refresh-cache'), {}, {
        preserveScroll: true,
        onFinish: () => { refreshingCache.value = false; },
    });
};

/* ------------------------------------------------------------------ */
/* Manage Account (Registrar / Dean / OIC / Assistant Dean)            */
/* ------------------------------------------------------------------ */
const accountForm = useForm({
    first_name: page.props.auth?.user?.first_name ?? '',
    middle_name: page.props.auth?.user?.middle_name ?? '',
    last_name: page.props.auth?.user?.last_name ?? '',
    suffix: page.props.auth?.user?.suffix ?? '',
    email: page.props.auth?.user?.email ?? '',
    password: '',
    password_confirmation: '',
});
const onUpdateAccount = () => {
    accountForm.transform((data) => ({ ...data, _method: 'put' })).post(route('account.update'), {
        preserveScroll: true,
        onSuccess: () => { accountForm.password = ''; accountForm.password_confirmation = ''; },
        onError,
    });
};
</script>

<template>
    <Head title="Settings" />

    <AppLayout>
        <Toast />

        <div class="max-w-6xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <div class="mb-6">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">Settings</h1>
                <p class="mt-1 text-slate-500 max-w-3xl">
                    System-wide configuration. Faculty, Rooms, Subjects, Sections, Programs, Colleges and the
                    Curriculum keep their own dedicated pages — Settings only controls how the system behaves.
                </p>
            </div>

            <Tabs v-model:value="activeTab">
                <TabList>
                    <Tab v-if="has('general')" value="general">General</Tab>
                    <Tab v-if="has('academic')" value="academic">Academic</Tab>
                    <Tab v-if="has('meeting')" value="meeting">Meeting Frequency</Tab>
                    <Tab v-if="has('workload')" value="workload">Faculty &amp; Workload</Tab>
                    <Tab v-if="has('rooms')" value="rooms">Rooms</Tab>
                    <Tab v-if="has('autoschedule')" value="autoschedule">Auto Schedule</Tab>
                    <Tab v-if="has('irregular')" value="irregular">Irregular Scheduling</Tab>
                    <Tab v-if="has('notifications')" value="notifications">Notifications</Tab>
                    <Tab v-if="has('system')" value="system">System</Tab>
                    <Tab v-if="!isAdministrator" value="account">Manage Account</Tab>
                </TabList>

                <TabPanels>
                    <!-- ============================== GENERAL ============================== -->
                    <TabPanel v-if="has('general')" value="general">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">General</h2>
                                <p class="text-sm text-slate-500 mb-6">School identity shown across the system.</p>

                                <fieldset :disabled="!canEdit('general')" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-7">
                                    <FloatLabel variant="on">
                                        <InputText id="schoolName" size="large" v-model="generalForm.school_name" class="w-full" :invalid="!!generalForm.errors.school_name" />
                                        <label for="schoolName">School Name *</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputText id="schoolShortName" size="large" v-model="generalForm.school_short_name" class="w-full" />
                                        <label for="schoolShortName">Short Name</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputText id="schoolContact" size="large" v-model="generalForm.school_contact" class="w-full" />
                                        <label for="schoolContact">Contact Number</label>
                                    </FloatLabel>

                                    <FloatLabel variant="on">
                                        <InputText id="schoolEmail" size="large" v-model="generalForm.school_email" type="email" class="w-full" :invalid="!!generalForm.errors.school_email" />
                                        <label for="schoolEmail">School Email</label>
                                    </FloatLabel>

                                    <FloatLabel variant="on" class="sm:col-span-2 lg:col-span-3">
                                        <Textarea id="schoolAddress" v-model="generalForm.school_address" class="w-full" rows="1" autoResize />
                                        <label for="schoolAddress">Address</label>
                                    </FloatLabel>
                                </fieldset>

                                <Divider class="!my-6" />

                                <div class="flex flex-col sm:flex-row sm:items-center gap-5">
                                    <img v-if="settings['general.school_logo_path']" :src="settings['general.school_logo_path']" class="h-16 w-16 object-cover rounded-xl border border-slate-100 shrink-0" alt="Current logo" />
                                    <div class="h-16 w-16 rounded-xl border border-dashed border-slate-200 flex items-center justify-center text-slate-300 shrink-0" v-else>
                                        <i class="pi pi-image text-xl"></i>
                                    </div>
                                    <div class="flex-1">
                                        <label class="text-sm text-slate-600 font-medium mb-1 block">School Logo</label>
                                        <input type="file" accept="image/*" :disabled="!canEdit('general')" @change="onLogoChange" class="text-sm" />
                                        <small v-if="generalForm.errors.logo" class="text-red-500 block mt-1">{{ generalForm.errors.logo }}</small>
                                    </div>
                                </div>

                                <div v-if="canEdit('general')" class="flex justify-end mt-6">
                                    <Button label="Save Changes" icon="pi pi-check" :loading="generalForm.processing" @click="saveGeneral" />
                                </div>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- ============================== ACADEMIC ============================== -->
                    <TabPanel v-if="has('academic')" value="academic">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">Academic Defaults</h2>
                                <p class="text-sm text-slate-500 mb-5">
                                    Default values used when creating new Sections, Schedules, and Reports. The
                                    <a :href="route('academic-calendar')" class="text-[#2563EB] underline">Academic Calendar</a>
                                    remains the source of truth for actual School Year / Semester periods and dates.
                                </p>

                                <fieldset :disabled="!canEdit('academic')" class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                    <FloatLabel variant="on">
                                        <InputText id="defaultAY" size="large" v-model="academicForm.default_academic_year" class="w-full" placeholder="e.g. 2026-2027" />
                                        <label for="defaultAY">Default Academic Year</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <Select id="defaultSem" size="large" v-model="academicForm.default_semester" :options="semesterOptions" class="w-full" />
                                        <label for="defaultSem">Default Semester</label>
                                    </FloatLabel>
                                </fieldset>

                                <div v-if="canEdit('academic')" class="flex justify-end mt-6">
                                    <Button label="Save Changes" icon="pi pi-check" :loading="academicForm.processing" @click="saveAcademic" />
                                </div>
                            </template>
                        </Card>

                        <Card v-if="schoolYear" class="!rounded-2xl border border-slate-100 shadow-sm mt-5">
                            <template #content>
                                <div class="flex items-center justify-between mb-1">
                                    <h2 class="text-lg font-bold text-[#1E293B]">Scheduling Window &amp; Calendar</h2>
                                    <Tag severity="info" value="Managed in Academic Calendar" />
                                </div>
                                <p class="text-sm text-slate-500 mb-4">
                                    Class Start/End Time, Time Interval, Working Days, and Lunch Break already live on the
                                    Active School Year so the Auto Schedule engine has one source of truth. Change them from the
                                    Academic Calendar page — Settings only shows the current values here.
                                </p>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                                    <div><span class="text-slate-400 block">Active School Year</span>{{ schoolYear.name }}</div>
                                    <div><span class="text-slate-400 block">Class Hours</span>{{ schoolYear.class_start_time }} – {{ schoolYear.class_end_time }}</div>
                                    <div><span class="text-slate-400 block">Time Interval</span>{{ schoolYear.time_interval }} minutes</div>
                                    <div><span class="text-slate-400 block">Lunch Break</span>{{ schoolYear.lunch_start }} – {{ schoolYear.lunch_end }} <Tag severity="danger" value="HARD CONSTRAINT" class="!text-[10px] ml-1" /></div>
                                    <div class="sm:col-span-2"><span class="text-slate-400 block">Working Days</span>{{ schoolYear.available_days.join(', ') }}</div>
                                </div>
                                <Button as="a" :href="route('academic-calendar')" label="Open Academic Calendar" icon="pi pi-external-link" text class="!mt-4 !px-0" />
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- ============================== MEETING FREQUENCY ============================== -->
                    <TabPanel v-if="has('meeting')" value="meeting">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">Subject Meeting Frequency</h2>
                                <p class="text-sm text-slate-500 mb-5">
                                    Controls which meeting patterns Auto Schedule is allowed to use. This does not force
                                    every subject into the same frequency — each subject's configured hours/week still
                                    determines the appropriate pattern (e.g. 5 hrs/week as 1×5h or 2×2.5h).
                                </p>

                                <fieldset :disabled="!canEdit('meeting')" class="space-y-3">
                                    <div class="flex items-center gap-3">
                                        <ToggleSwitch v-model="meetingForm.allow_1x" />
                                        <span>1× per week</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <ToggleSwitch v-model="meetingForm.allow_2x" />
                                        <span>2× per week</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <ToggleSwitch v-model="meetingForm.allow_3x" />
                                        <span>3× per week <span class="text-xs text-slate-400">(disabled by default — enable explicitly)</span></span>
                                    </div>
                                </fieldset>
                                <small v-if="meetingForm.errors.allow_1x" class="text-red-500 block mt-2">{{ meetingForm.errors.allow_1x }}</small>

                                <div v-if="canEdit('meeting')" class="flex justify-end mt-6">
                                    <Button label="Save Changes" icon="pi pi-check" :loading="meetingForm.processing" @click="saveMeeting" />
                                </div>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- ============================== FACULTY & WORKLOAD ============================== -->
                    <TabPanel v-if="has('workload')" value="workload">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">Faculty &amp; Workload</h2>
                                <p class="text-sm text-slate-500 mb-5">
                                    Auto Schedule prefers faculty with lower current workload and never auto-assigns past
                                    the maximum load. Individual faculty-specific limits (if set on a Faculty record)
                                    still take precedence over this system default.
                                </p>

                                <fieldset :disabled="!canEdit('workload')" class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                                    <FloatLabel variant="on">
                                        <InputNumber id="maxLoad" size="large" v-model="workloadForm.max_teaching_load" class="w-full" :min="1" :max="60" />
                                        <label for="maxLoad">Max Teaching Load (units/hrs)</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputNumber id="warnThreshold" size="large" v-model="workloadForm.warning_threshold" class="w-full" :min="0" :max="100" suffix="%" />
                                        <label for="warnThreshold">Warning Threshold</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <InputNumber id="overloadThreshold" size="large" v-model="workloadForm.overloaded_threshold" class="w-full" :min="0" :max="200" suffix="%" />
                                        <label for="overloadThreshold">Overloaded Threshold</label>
                                    </FloatLabel>
                                </fieldset>
                                <small v-if="workloadForm.errors.warning_threshold" class="text-red-500 block mt-2">{{ workloadForm.errors.warning_threshold }}</small>

                                <div class="flex items-center gap-3 mt-5">
                                    <ToggleSwitch v-model="workloadForm.allow_admin_override" :disabled="!canEdit('workload')" />
                                    <span>Allow Administrator override above the maximum load</span>
                                    <Tag severity="danger" value="HARD CONSTRAINT unless overridden" class="!text-[10px]" />
                                </div>

                                <div v-if="canEdit('workload')" class="flex justify-end mt-6">
                                    <Button label="Save Changes" icon="pi pi-check" :loading="workloadForm.processing" @click="saveWorkload" />
                                </div>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- ============================== ROOMS ============================== -->
                    <TabPanel v-if="has('rooms')" value="rooms">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">Rooms</h2>
                                <p class="text-sm text-slate-500 mb-5">
                                    Controls Auto Schedule's room-recommendation behavior only. Actual rooms and their
                                    capacity/type remain managed on the
                                    <a :href="route('scheduling.rooms')" class="text-[#2563EB] underline">Rooms</a> page.
                                </p>

                                <div class="flex items-center gap-3 mb-5">
                                    <ToggleSwitch v-model="roomsForm.enable_recommendations" :disabled="!canEdit('rooms')" />
                                    <span>Use room recommendations during Auto Schedule</span>
                                </div>

                                <label class="text-sm text-slate-600 font-medium mb-2 block">Room Recommendation Priority (top = highest priority)</label>
                                <ul class="space-y-2">
                                    <li v-for="(key, index) in roomsForm.priority_order" :key="key" class="flex items-center justify-between border border-slate-100 rounded-lg px-3 py-2">
                                        <span class="text-sm">{{ index + 1 }}. {{ roomPriorityLabels[key] }}</span>
                                        <div class="flex gap-1" v-if="canEdit('rooms')">
                                            <Button icon="pi pi-arrow-up" text size="small" :disabled="index === 0" @click="moveRoomPriority(index, -1)" />
                                            <Button icon="pi pi-arrow-down" text size="small" :disabled="index === roomsForm.priority_order.length - 1" @click="moveRoomPriority(index, 1)" />
                                        </div>
                                    </li>
                                </ul>

                                <div v-if="canEdit('rooms')" class="flex justify-end mt-6">
                                    <Button label="Save Changes" icon="pi pi-check" :loading="roomsForm.processing" @click="saveRooms" />
                                </div>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- ============================== AUTO SCHEDULE / AI ============================== -->
                    <TabPanel v-if="has('autoschedule')" value="autoschedule">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">Auto Schedule / AI</h2>
                                <p class="text-sm text-slate-500 mb-2">
                                    Controls how aggressively Auto Schedule optimizes. Soft preferences below are used to
                                    rank valid solutions — they can never override a
                                    <Tag severity="danger" value="HARD CONSTRAINT" class="!text-[10px] align-middle" /> such as
                                    Faculty/Room conflict, Lunch Break, a disabled day, or outside the scheduling window.
                                </p>

                                <fieldset :disabled="!canEdit('autoschedule')">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-4">
                                        <FloatLabel variant="on">
                                            <Select id="autoMode" size="large" v-model="autoScheduleForm.mode" :options="autoModeOptions" optionLabel="label" optionValue="value" class="w-full" />
                                            <label for="autoMode">Auto Schedule Mode</label>
                                        </FloatLabel>
                                        <FloatLabel variant="on">
                                            <InputNumber id="maxContinuous" size="large" v-model="autoScheduleForm.max_continuous_duration_hours" class="w-full" :min="1" :max="12" suffix=" hrs" />
                                            <label for="maxContinuous">Max Continuous Class Duration</label>
                                        </FloatLabel>
                                    </div>

                                    <div class="flex items-center gap-3 mt-5">
                                        <ToggleSwitch v-model="autoScheduleForm.enable_daily_load_optimization" />
                                        <span>Enable Section Daily Load Optimization <Tag severity="secondary" value="SOFT PREFERENCE" class="!text-[10px]" /></span>
                                    </div>

                                    <Divider class="!my-5" />

                                    <label class="text-sm text-slate-600 font-medium mb-2 block">Optimization Priorities <Tag severity="secondary" value="SOFT PREFERENCE" class="!text-[10px]" /></label>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div v-for="(label, key) in priorityLabels" :key="key" class="flex items-center justify-between border border-slate-100 rounded-lg px-3 py-2">
                                            <span class="text-sm">{{ label }}</span>
                                            <Select v-model="autoScheduleForm.priorities[key]" :options="priorityLevels" optionLabel="label" optionValue="value" class="!w-28" />
                                        </div>
                                    </div>
                                </fieldset>

                                <div v-if="canEdit('autoschedule')" class="flex justify-end mt-6">
                                    <Button label="Save Changes" icon="pi pi-check" :loading="autoScheduleForm.processing" @click="saveAutoSchedule" />
                                </div>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- ============================== IRREGULAR SCHEDULING ============================== -->
                    <TabPanel v-if="has('irregular')" value="irregular">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">Irregular Scheduling</h2>
                                <Message severity="warn" :closable="false" class="mb-4 !text-sm">
                                    Student-level conflict checking is unavailable because student enrollment data is not
                                    currently stored in Classly.
                                </Message>

                                <fieldset :disabled="!canEdit('irregular')" class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                    <FloatLabel variant="on">
                                        <InputNumber id="defEstStudents" size="large" v-model="irregularForm.default_estimated_students" class="w-full" :min="1" :max="200" />
                                        <label for="defEstStudents">Default Estimated Students</label>
                                    </FloatLabel>
                                    <FloatLabel variant="on">
                                        <Select id="irregularMode" size="large" v-model="irregularForm.default_mode" :options="irregularModeOptions" optionLabel="label" optionValue="value" class="w-full" />
                                        <label for="irregularMode">Default Irregular Scheduling Mode</label>
                                    </FloatLabel>
                                </fieldset>
                                <p class="text-xs text-slate-400 mt-1">An individual Irregular Section can still override its own estimated students.</p>

                                <div class="flex items-center gap-3 mt-5">
                                    <ToggleSwitch v-model="irregularForm.enable_merge_recommendations" :disabled="!canEdit('irregular')" />
                                    <span>Enable Merge Recommendations</span>
                                </div>

                                <div v-if="canEdit('irregular')" class="flex justify-end mt-6">
                                    <Button label="Save Changes" icon="pi pi-check" :loading="irregularForm.processing" @click="saveIrregular" />
                                </div>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- ============================== NOTIFICATIONS ============================== -->
                    <TabPanel v-if="has('notifications')" value="notifications">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">Notifications</h2>
                                <p class="text-sm text-slate-500 mb-5">Controls which in-app warnings/notifications are shown.</p>

                                <fieldset :disabled="!canEdit('notifications')" class="space-y-3">
                                    <div class="flex items-center gap-3"><ToggleSwitch v-model="notificationsForm.schedule_conflict" /><span>Schedule Conflict Notifications</span></div>
                                    <div class="flex items-center gap-3"><ToggleSwitch v-model="notificationsForm.workload_warning" /><span>Faculty Workload Warnings</span></div>
                                    <div class="flex items-center gap-3"><ToggleSwitch v-model="notificationsForm.room_conflict" /><span>Room Conflict Notifications</span></div>
                                    <div class="flex items-center gap-3"><ToggleSwitch v-model="notificationsForm.unscheduled_subject" /><span>Unscheduled Subject Notifications</span></div>
                                    <div class="flex items-center gap-3"><ToggleSwitch v-model="notificationsForm.merge_recommendation" /><span>Merge Recommendation Notifications</span></div>
                                </fieldset>

                                <div v-if="canEdit('notifications')" class="flex justify-end mt-6">
                                    <Button label="Save Changes" icon="pi pi-check" :loading="notificationsForm.processing" @click="saveNotifications" />
                                </div>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- ============================== SYSTEM ============================== -->
                    <TabPanel v-if="has('system') && system" value="system">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">System / Maintenance</h2>
                                <p class="text-sm text-slate-500 mb-5">Visible to Administrators only.</p>

                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm mb-6">
                                    <div><span class="text-slate-400 block">Application Version</span>{{ system.app_version }}</div>
                                    <div><span class="text-slate-400 block">Laravel Version</span>{{ system.laravel_version }}</div>
                                    <div>
                                        <span class="text-slate-400 block">Database Status</span>
                                        <Tag :severity="system.database_status === 'Connected' ? 'success' : 'danger'" :value="system.database_status" />
                                    </div>
                                    <div><span class="text-slate-400 block">Last Configuration Update</span>{{ system.last_configuration_update ?? '—' }}</div>
                                </div>

                                <Button label="Refresh Configuration Cache" icon="pi pi-refresh" severity="secondary" outlined :loading="refreshingCache" @click="refreshCache" />
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- ============================== MANAGE ACCOUNT ============================== -->
                    <TabPanel v-if="!isAdministrator" value="account">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm max-w-2xl">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">Manage Account</h2>
                                <p class="text-sm text-slate-500 mb-5">Update your own profile and change your password.</p>

                                <form class="pt-1" autocomplete="off" @submit.prevent="onUpdateAccount">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                        <FloatLabel variant="on">
                                            <InputText id="accFirstName" size="large" v-model="accountForm.first_name" class="w-full" autocomplete="off" :invalid="!!accountForm.errors.first_name" />
                                            <label for="accFirstName">First Name *</label>
                                        </FloatLabel>
                                        <FloatLabel variant="on">
                                            <InputText id="accMiddleName" size="large" v-model="accountForm.middle_name" class="w-full" autocomplete="off" />
                                            <label for="accMiddleName">Middle Name</label>
                                        </FloatLabel>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                                        <FloatLabel variant="on">
                                            <InputText id="accLastName" size="large" v-model="accountForm.last_name" class="w-full" autocomplete="off" :invalid="!!accountForm.errors.last_name" />
                                            <label for="accLastName">Last Name *</label>
                                        </FloatLabel>
                                        <FloatLabel variant="on">
                                            <InputText id="accSuffix" size="large" v-model="accountForm.suffix" class="w-full" autocomplete="off" />
                                            <label for="accSuffix">Suffix</label>
                                        </FloatLabel>
                                    </div>

                                    <Divider class="!my-5" />

                                    <div class="grid grid-cols-1 gap-5">
                                        <FloatLabel variant="on">
                                            <InputText id="accEmail" size="large" v-model="accountForm.email" type="email" class="w-full" autocomplete="off" :invalid="!!accountForm.errors.email" />
                                            <label for="accEmail">Email *</label>
                                        </FloatLabel>
                                        <small v-if="accountForm.errors.email" class="text-red-500 -mt-4">{{ accountForm.errors.email }}</small>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                                        <FloatLabel variant="on">
                                            <Password id="accPassword" size="large" v-model="accountForm.password" toggleMask :feedback="true" inputClass="w-full" class="w-full" autocomplete="new-password" :invalid="!!accountForm.errors.password" />
                                            <label for="accPassword">New Password</label>
                                        </FloatLabel>
                                        <FloatLabel variant="on">
                                            <Password id="accPasswordConfirm" size="large" v-model="accountForm.password_confirmation" toggleMask :feedback="false" inputClass="w-full" class="w-full" autocomplete="new-password" :invalid="!!accountForm.errors.password" />
                                            <label for="accPasswordConfirm">Confirm New Password</label>
                                        </FloatLabel>
                                    </div>
                                    <small v-if="accountForm.errors.password" class="text-red-500">{{ accountForm.errors.password }}</small>
                                    <p class="text-xs text-slate-400 mt-1">Leave blank to keep your current password.</p>

                                    <div class="flex justify-end mt-6">
                                        <Button type="submit" label="Save Changes" icon="pi pi-check" :loading="accountForm.processing" />
                                    </div>
                                </form>
                            </template>
                        </Card>
                    </TabPanel>
                </TabPanels>
            </Tabs>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (applied on the root container when isDark is true) lets these rules
   restyle the light-themed Tailwind/PrimeVue defaults used throughout
   this page without touching the light-mode markup. */
/* Token overrides: PrimeVue Aura reads these CSS variables for every
   component's colors. Setting them here means anything nested under
   .dark-scope repaints correctly even if a class-level rule below
   doesn't happen to match that component's internal markup. */
.dark-scope {
    --p-card-background: #141B33;
    --p-card-color: #F8FAFC;
    --p-content-background: #141B33;
    --p-content-color: #F8FAFC;
    --p-inputtext-background: rgba(255, 255, 255, 0.06);
    --p-inputtext-color: #F8FAFC;
    --p-inputtext-border-color: rgba(255, 255, 255, 0.18);
    --p-inputtext-placeholder-color: #64748B;
    --p-select-background: rgba(255, 255, 255, 0.06);
    --p-select-color: #F8FAFC;
    --p-select-border-color: rgba(255, 255, 255, 0.18);
    --p-select-overlay-background: #141B33;
}

.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-\[\#2563EB\]) { color: #60A5FA !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.bg-white) { background-color: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.bg-slate-50) { background-color: rgba(255, 255, 255, 0.04) !important; }
.dark-scope :deep(.bg-slate-100) { background-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }
.dark-scope :deep(.border-dashed) { border-color: rgba(255, 255, 255, 0.2) !important; }
.dark-scope :deep(.text-slate-300) { color: #475569 !important; }

.dark-scope :deep(.p-card) { background: #141B33 !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }
.dark-scope :deep(.p-card .p-card-content) { background: transparent !important; }

.dark-scope :deep(.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-password-input),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect),
.dark-scope :deep(.p-inputnumber-input),
.dark-scope :deep(.p-textarea) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.18) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }
.dark-scope :deep(.p-select-overlay),
.dark-scope :deep(.p-multiselect-overlay) { background: #141B33 !important; border-color: rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-select-option),
.dark-scope :deep(.p-multiselect-option) { color: #E2E8F0 !important; }
.dark-scope :deep(.p-select-option:hover),
.dark-scope :deep(.p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }

/* FloatLabel variant="on" keeps the label permanently floated, cutting
   through the input's top border — its background must always match
   the surface behind it (the card), not just on focus, or it renders
   as a mismatched opaque chip like the one in the earlier screenshot. */
.dark-scope :deep(.p-floatlabel-on label) { background: #141B33 !important; color: #94A3B8 !important; }
.dark-scope :deep(.p-floatlabel-on:has(input:focus) label),
.dark-scope :deep(.p-floatlabel-on:has(.p-inputwrapper-focus) label) { color: #60A5FA !important; }

.dark-scope :deep(.p-divider.p-divider-horizontal:before) { border-color: rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }
.dark-scope :deep(.p-tablist-nav-button) {
    background: rgba(15, 23, 48, 0.9) !important;
    color: #60A5FA !important;
    border: 1px solid rgba(96, 165, 250, 0.5) !important;
    border-radius: 999px !important;
    box-shadow: 0 0 8px rgba(96, 165, 250, 0.55), 0 0 18px rgba(96, 165, 250, 0.25) !important;
    transition: box-shadow 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.dark-scope :deep(.p-tablist-nav-button:hover) {
    color: #93C5FD !important;
    border-color: rgba(147, 197, 253, 0.8) !important;
    box-shadow: 0 0 12px rgba(147, 197, 253, 0.8), 0 0 26px rgba(147, 197, 253, 0.4) !important;
}
.dark-scope :deep(.p-tabs) { background: transparent !important; }
.dark-scope :deep(.p-tabpanels) { background: transparent !important; color: #F8FAFC !important; padding: 0 !important; }
.dark-scope :deep(.p-tablist-tab-list) { background: transparent !important; }
.dark-scope :deep(.p-tablist-active-bar) { background: #60A5FA !important; }

.dark-scope :deep(.p-togglebutton),
.dark-scope :deep(.p-checkbox-box) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.2) !important; }
.dark-scope :deep(.p-toggleswitch:not(.p-toggleswitch-checked) .p-toggleswitch-slider) { background: rgba(255, 255, 255, 0.15) !important; }

.dark-scope :deep(.p-message) { background: rgba(255, 255, 255, 0.06) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-message-text) { color: #F8FAFC !important; }

.dark-scope :deep(.p-menu) { background: #0F1730 !important; border-color: rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-menu .p-menu-item-link) { color: #E2E8F0 !important; }
.dark-scope :deep(.p-menu .p-menu-item-link:hover) { background: rgba(255, 255, 255, 0.06) !important; }
</style>