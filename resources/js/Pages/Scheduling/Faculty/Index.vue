<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';
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
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    faculties: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    filters: {
        type: Object,
        default: () => ({ faculty_search: '', faculty_category: '' }),
    },
    colleges: { type: Array, default: () => [] },
    nextFacultyId: { type: String, default: '' },

    // Faculty Load Requests section (moved here from its own page —
    // see FacultyController@loadRequestsProps).
    loadRequests: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    pendingLoadRequestsCount: { type: Number, default: 0 },
    hardCapUnits: { type: Number, default: 40 },
    loadRequestFaculties: { type: Array, default: () => [] },
    canReviewLoadRequests: { type: Boolean, default: false },
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

/* ------------------------------------------------------------------ */
/* Load ceiling permission — see FacultyPolicy::changeMaxLoad()        */
/* ------------------------------------------------------------------ */

// Admin/Registrar only. Everyone else sees Maximum Teaching Units as
// read-only in this form and must use "Request Load Increase" instead
// — the server independently pins the field back either way
// (FacultyController::update()/store()), this is just the UI hint.
const canChangeMaxLoad = computed(() => !!page.props.auth?.can?.changeFacultyMaxLoad);

const loadRequestVisible = ref(false);
// When opened from the Edit Faculty dialog the faculty is already
// known and the picker is locked; when opened from the "New Request"
// button in the Load Requests section below, the Dean/OIC/Assistant
// Dean picks any faculty member they can see.
const lockLoadRequestFaculty = ref(false);
const loadRequestForm = useForm({
    faculty_id: null,
    requested_max_teaching_units: null,
    reason: '',
});

const loadRequestSelectedFaculty = computed(
    () => props.loadRequestFaculties.find((f) => f.id === loadRequestForm.faculty_id) ?? null,
);

const openLoadRequest = (faculty) => {
    loadRequestForm.reset();
    loadRequestForm.clearErrors();
    lockLoadRequestFaculty.value = true;
    loadRequestForm.faculty_id = faculty.id;
    loadRequestForm.requested_max_teaching_units = faculty.max_teaching_units + 1;
    loadRequestVisible.value = true;
};

const openNewLoadRequest = () => {
    loadRequestForm.reset();
    loadRequestForm.clearErrors();
    lockLoadRequestFaculty.value = false;
    loadRequestVisible.value = true;
};

watch(
    () => loadRequestForm.faculty_id,
    (facultyId) => {
        if (lockLoadRequestFaculty.value) return;
        const faculty = props.loadRequestFaculties.find((f) => f.id === facultyId);
        loadRequestForm.requested_max_teaching_units = faculty ? faculty.max_teaching_units + 1 : null;
    },
);

const closeLoadRequest = () => {
    loadRequestVisible.value = false;
    lockLoadRequestFaculty.value = false;
    loadRequestForm.reset();
    loadRequestForm.clearErrors();
};

const onSubmitLoadRequest = () => {
    loadRequestForm.post(route('scheduling.faculty-load-requests.store'), {
        preserveScroll: true,
        onSuccess: () => {
            closeLoadRequest();
            closeAddFaculty();
            Swal.fire(
                props.canReviewLoadRequests
                    ? {
                        title: 'Load updated',
                        text: 'The faculty member\'s teaching load ceiling has been updated.',
                        icon: 'success',
                        confirmButtonColor: '#16A34A',
                    }
                    : {
                        title: 'Request submitted',
                        text: 'An Administrator or Registrar will review this load increase request.',
                        icon: 'success',
                        confirmButtonColor: '#16A34A',
                    },
            );
        },
    });
};

/* ------------------------------------------------------------------ */
/* Load Requests section — list, paginate, review (Admin/Registrar)    */
/* ------------------------------------------------------------------ */

const loadRequestsLoading = ref(false);
const loadRequestsListVisible = ref(false);
// Dismiss is per page-visit only (not persisted) — a fresh load or a
// newly-submitted request should surface the banner again.
const pendingBannerDismissed = ref(false);

const onLoadRequestsPage = (event) => {
    loadRequestsLoading.value = true;
    router.get(
        route('scheduling.faculty'),
        { load_requests_page: event.page + 1 },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['loadRequests'],
            onFinish: () => {
                loadRequestsLoading.value = false;
            },
        },
    );
};

const reviewingRequest = ref(null);
const reviewForm = useForm({
    decision: 'Approved',
    decision_note: '',
    approved_max_teaching_units: null,
    approved_max_weekly_hours: null,
});

const openReview = (requestRow, decision) => {
    reviewingRequest.value = requestRow;
    reviewForm.reset();
    reviewForm.clearErrors();
    reviewForm.decision = decision;
    // Prefill with exactly what was requested — Admin/Registrar can
    // still edit this before approving to grant a different ceiling.
    reviewForm.approved_max_teaching_units = requestRow.requested_max_teaching_units;
    reviewForm.approved_max_weekly_hours = requestRow.requested_max_weekly_hours;
};

const closeReview = () => {
    reviewingRequest.value = null;
    reviewForm.reset();
    reviewForm.clearErrors();
};

const onSubmitReview = () => {
    reviewForm.put(route('scheduling.faculty-load-requests.review', reviewingRequest.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeReview();
        },
    });
};

const loadRequestStatusSeverity = (status) => ({ Pending: 'warn', Approved: 'success', Denied: 'danger' }[status] ?? 'secondary');

const onDeleteLoadRequest = (requestRow) => {
    const facultyName = `${requestRow.faculty?.first_name ?? ''} ${requestRow.faculty?.last_name ?? ''}`.trim();
    Swal.fire({
        title: 'Delete this load request?',
        text: `This ${requestRow.status.toLowerCase()} request for ${facultyName} will be permanently removed from the list.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('scheduling.faculty-load-requests.destroy', requestRow.id), {
                preserveScroll: true,
                only: ['loadRequests', 'pendingLoadRequestsCount'],
            });
        }
    });
};

/* ------------------------------------------------------------------ */
/* Live-ish load request updates                                       */
/*                                                                      */
/* There's no Reverb/websocket push in this app — the bell already     */
/* polls unread count every 20s, but the Faculty page's own data       */
/* (the requests table, the pending-review banner, the "Teaching       */
/* Load" column) stays exactly as it was when the page loaded until    */
/* someone manually refreshes. That's confusing on both ends of a      */
/* request: the Dean/OIC who submitted it has no idea it was decided   */
/* until they happen to open the bell, and Admin/Registrar's own       */
/* pending count can be stale too if two reviewers are working at      */
/* once. So, independently of the bell, poll the same lightweight      */
/* "recent notifications" endpoint, watch for load-request events      */
/* addressed to this user, and when one shows up: pop a toast right    */
/* here on the page, and flag the data as stale with a one-click       */
/* "Refresh" banner instead of quietly doing nothing until F5.         */
/* ------------------------------------------------------------------ */

const LOAD_REQUEST_NOTIFICATION_TYPES = ['FACULTY_LOAD_REQUEST_REVIEWED', 'FACULTY_LOAD_REQUEST_SUBMITTED'];
const lastSeenLoadRequestNotificationId = ref(null);
const staleLoadRequestUpdate = ref(false);
const refreshingLoadRequests = ref(false);
let loadRequestPollTimer = null;

const refreshLoadRequestData = () => {
    refreshingLoadRequests.value = true;
    router.reload({
        only: ['loadRequests', 'pendingLoadRequestsCount', 'faculties'],
        preserveScroll: true,
        onFinish: () => {
            refreshingLoadRequests.value = false;
            staleLoadRequestUpdate.value = false;
        },
    });
};

const pollLoadRequestNotifications = async () => {
    try {
        const { data } = await axios.get(route('notifications.recent'));
        const relevant = (data.notifications ?? []).filter((n) => LOAD_REQUEST_NOTIFICATION_TYPES.includes(n.type));
        if (relevant.length === 0) return;

        const newestId = Math.max(...relevant.map((n) => n.id));

        // First tick after landing on the page just establishes the
        // baseline — nothing already sitting in the list should pop a
        // toast for something that happened before we started watching.
        if (lastSeenLoadRequestNotificationId.value === null) {
            lastSeenLoadRequestNotificationId.value = newestId;
            return;
        }

        const freshOnes = relevant.filter((n) => n.id > lastSeenLoadRequestNotificationId.value);
        if (freshOnes.length === 0) return;

        lastSeenLoadRequestNotificationId.value = newestId;
        staleLoadRequestUpdate.value = true;

        freshOnes.forEach((n) => {
            toast.add({
                severity: n.type === 'FACULTY_LOAD_REQUEST_REVIEWED'
                    ? (n.data?.status === 'Approved' ? 'success' : 'warn')
                    : 'info',
                summary: n.title,
                detail: n.message,
                life: 8000,
            });
        });
    } catch (e) {
        // A failed poll tick isn't worth surfacing — it just retries
        // on the next interval.
    }
};

onMounted(() => {
    pollLoadRequestNotifications();
    loadRequestPollTimer = setInterval(pollLoadRequestNotifications, 20000);
});

onUnmounted(() => {
    if (loadRequestPollTimer) clearInterval(loadRequestPollTimer);
});

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
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Faculty Master</span>
        </template>

        <div class="max-w-7xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Page Title -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                    Faculty Master
                    <InfoPopover
                        title="Faculty Master"
                        :paragraphs="[
                            'The master roster of faculty members available for teaching assignments across the institution.',
                        ]"
                        :bullets="[
                            'Max Teaching Units caps how many units a faculty member can be assigned per term; Teaching Load shows current usage against that cap.',
                            'Faculty with no College are treated as General Education Faculty and can be assigned across colleges.',
                            'Inactive faculty stay in the system for history but are not offered when assigning new teaching loads.',
                            'Use the view icon to manage a faculty member\'s teaching qualifications, availability, and workload details.',
                        ]"
                    />
                </h1>
                <p class="mt-1" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                    Manage faculty members available for teaching assignments.
                </p>
            </div>

            <!-- Stale load-request data banner — fires when the poller catches a
                 submit/approve/deny that happened elsewhere while this page sat
                 open (no Reverb here, so nothing pushes it in automatically). -->
            <div
                v-if="staleLoadRequestUpdate"
                class="mb-6 rounded-2xl border px-5 py-4 flex items-start sm:items-center gap-4 flex-col sm:flex-row justify-between"
                :class="isDark
                    ? 'bg-blue-500/10 border-blue-500/30'
                    : 'bg-blue-50 border-blue-200'"
            >
                <div class="flex items-start sm:items-center gap-3">
                    <i class="pi pi-info-circle text-lg mt-0.5 sm:mt-0" :class="isDark ? 'text-blue-300' : 'text-blue-600'" />
                    <div>
                        <p class="font-semibold text-sm" :class="isDark ? 'text-blue-200' : 'text-blue-800'">
                            A faculty load request was just updated
                        </p>
                        <p class="text-xs" :class="isDark ? 'text-blue-300/80' : 'text-blue-700'">
                            This page doesn't auto-update — refresh to see the latest status and numbers.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0 self-end sm:self-auto">
                    <Button
                        label="Refresh"
                        icon="pi pi-refresh"
                        size="small"
                        severity="info"
                        :loading="refreshingLoadRequests"
                        @click="refreshLoadRequestData"
                    />
                    <Button icon="pi pi-times" text rounded size="small" severity="secondary" aria-label="Dismiss" @click="staleLoadRequestUpdate = false" />
                </div>
            </div>

            <!-- Pending Faculty Load Requests reminder — Admin/Registrar only -->
            <div
                v-if="canReviewLoadRequests && pendingLoadRequestsCount > 0 && !pendingBannerDismissed"
                class="mb-6 rounded-2xl border px-5 py-4 flex items-start sm:items-center gap-4 flex-col sm:flex-row justify-between"
                :class="isDark
                    ? 'bg-amber-500/10 border-amber-500/30'
                    : 'bg-amber-50 border-amber-200'"
            >
                <div class="flex items-start sm:items-center gap-3">
                    <i class="pi pi-bell text-lg mt-0.5 sm:mt-0" :class="isDark ? 'text-amber-400' : 'text-amber-600'" />
                    <div>
                        <p class="font-semibold text-sm" :class="isDark ? 'text-amber-200' : 'text-amber-800'">
                            {{ pendingLoadRequestsCount }} faculty load {{ pendingLoadRequestsCount === 1 ? 'request' : 'requests' }} awaiting your review
                        </p>
                        <p class="text-xs" :class="isDark ? 'text-amber-300/80' : 'text-amber-700'">
                            A Dean/OIC has requested to raise a faculty member's teaching load ceiling.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0 self-end sm:self-auto">
                    <Button label="Review Now" size="small" severity="warning" @click="loadRequestsListVisible = true" />
                    <Button icon="pi pi-times" text rounded size="small" severity="secondary" aria-label="Dismiss" @click="pendingBannerDismissed = true" />
                </div>
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
                            <div class="flex flex-wrap items-center gap-3 w-full neu-form">
                                <span class="relative w-full sm:w-80">
                                    <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                                    <InputText
                                        v-uppercase
                                        v-model="search"
                                        placeholder="Search by ID, name, or college"
                                        class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                        :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                    />
                                </span>
                                <Select
                                    v-model="categoryFilter"
                                    :options="facultyCategoryOptions"
                                    placeholder="Filter by Department / General Education"
                                    showClear
                                    class="w-full sm:w-64"
                                    :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                />
                            </div>
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
                                <Button label="Add Faculty" icon="pi pi-plus" severity="success" @click="openAdd" />
                            </div>
                        </template>
                    </Toolbar>

                    <!-- Faculty Table -->
                    <p class="text-xs mb-2 flex items-center gap-1" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        <i class="pi pi-eye" :class="isDark ? 'text-slate-500' : 'text-slate-400'"></i>
                        Click a faculty member's name to manage their teaching qualifications and workload.
                    </p>
                    <DataTable
                        :value="faculties.data"
                        :loading="loading"
                        dataKey="id"
                        class="neu-inset neu-table rounded-xl overflow-hidden"
                        :class="isDark ? 'neu-table-dark' : ''"
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
                                <p class="font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-500'">No faculty members found.</p>
                                <p class="text-sm mt-1" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
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
                                <span
                                    class="cursor-pointer hover:underline"
                                    :class="isDark ? 'text-emerald-400' : 'text-emerald-700'"
                                    @click="router.visit(route('scheduling.faculty.show', data.id))"
                                >
                                    {{ fullName(data) }}
                                </span>
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
                        <Column style="width: 11rem">
                            <template #header>
                                <span class="flex items-center gap-1">
                                    Teaching Load
                                    <InfoPopover
                                        title="Teaching Load"
                                        :paragraphs="[
                                            'How many units this faculty member is currently assigned against their Max Teaching Units cap.',
                                        ]"
                                        :bullets="[
                                            '🟢 Green — comfortably under the cap.',
                                            '🟡 Yellow — nearing the cap.',
                                            '🔴 Red — at or over the cap; new assignments may be blocked or require override.',
                                        ]"
                                        width="w-64"
                                    />
                                </span>
                            </template>
                            <template #body="{ data }">
                                <div v-if="data.workload" class="flex items-center gap-1.5">
                                    <span>
                                        {{ data.workload.status === 'overloaded' ? '🔴' : data.workload.status === 'high' ? '🟡' : '🟢' }}
                                    </span>
                                    <span class="text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                                        {{ data.workload.current }} / {{ data.workload.max }} {{ data.workload.unit_label }}
                                    </span>
                                </div>
                            </template>
                        </Column>
                        <Column style="width: 9rem">
                            <template #header>
                                <span class="flex items-center gap-1">
                                    Status
                                    <InfoPopover
                                        title="Faculty Status"
                                        :bullets="[
                                            'Active — available for new teaching assignments.',
                                            'Inactive — kept for historical records; not offered for new assignments.',
                                        ]"
                                        width="w-64"
                                    />
                                </span>
                            </template>
                            <template #body="{ data }">
                                <Tag
                                    :value="data.status"
                                    :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                />
                            </template>
                        </Column>
                        <Column header="Actions" style="width: 11rem">
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <Button
                                        v-tooltip.top="'View details, teaching qualifications & workload'"
                                        icon="pi pi-eye"
                                        text
                                        rounded
                                        severity="info"
                                        size="small"
                                        aria-label="View"
                                        @click="router.visit(route('scheduling.faculty.show', data.id))"
                                    />
                                    <Button
                                        v-tooltip.top="'Faculty Load Requests'"
                                        icon="pi pi-send"
                                        text
                                        rounded
                                        severity="success"
                                        size="small"
                                        aria-label="Faculty Load Requests"
                                        @click="loadRequestsListVisible = true"
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
        </div>

        <!-- Faculty Load Requests (opened via the row icon beside "View") -->
        <Dialog
            v-model:visible="loadRequestsListVisible"
            modal
            header="Faculty Load Requests"
            :style="{ width: '64rem' }"
            :breakpoints="{ '1200px': '90vw', '640px': '95vw' }"
            :pt="{ root: { class: isDark ? 'dark-scope' : '' } }"
        >
            <template #header>
                <div>
                    <span class="text-xl font-bold flex items-center gap-2" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                        Faculty Load Requests
                        <InfoPopover
                            title="Faculty Load Requests"
                            :paragraphs="[
                                'Requests to raise a faculty member\'s teaching load ceiling above their current maximum (current max: ' + hardCapUnits + ' units — set under Settings > Faculty & Workload).',
                            ]"
                            :bullets="[
                                canReviewLoadRequests
                                    ? 'You can approve or deny requests below — approving updates the faculty member\'s ceiling immediately.'
                                    : 'An Administrator or Registrar reviews these; you\'ll see the decision here once it\'s made.',
                            ]"
                        />
                    </span>
                    <p class="mt-1 text-sm" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                        Raise a faculty member's teaching load ceiling above their current maximum.
                    </p>
                </div>
            </template>

            <div class="flex justify-end mb-3">
                <Button
                    :label="canReviewLoadRequests ? 'Add Load' : 'New Request'"
                    icon="pi pi-plus"
                    severity="success"
                    @click="openNewLoadRequest"
                />
            </div>

            <DataTable
                :value="loadRequests.data"
                :loading="loadRequestsLoading"
                dataKey="id"
                class="neu-inset neu-table rounded-xl overflow-hidden"
                :class="isDark ? 'neu-table-dark' : ''"
                stripedRows
                responsiveLayout="scroll"
                lazy
                paginator
                :rows="loadRequests.per_page"
                :totalRecords="loadRequests.total"
                :first="(loadRequests.current_page - 1) * loadRequests.per_page"
                @page="onLoadRequestsPage"
            >
                <template #empty>
                    <p class="text-center text-sm py-10" :class="isDark ? 'text-slate-400' : 'text-slate-400'">
                        No load requests yet. Click "{{ canReviewLoadRequests ? 'Add Load' : 'New Request' }}" above to submit one.
                    </p>
                </template>

                <Column field="faculty.faculty_id" header="Faculty">
                    <template #body="{ data }">
                        <div class="font-medium">{{ data.faculty?.first_name }} {{ data.faculty?.last_name }}</div>
                        <div class="text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'">{{ data.faculty?.faculty_id }}</div>
                    </template>
                </Column>
                <Column header="Current → Requested">
                    <template #body="{ data }">
                        {{ data.current_max_teaching_units }} → {{ data.requested_max_teaching_units }} units
                        <template v-if="data.requested_max_weekly_hours">
                            <br /><span class="text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'">{{ data.current_max_weekly_hours ?? 0 }} → {{ data.requested_max_weekly_hours }} hrs</span>
                        </template>
                    </template>
                </Column>
                <Column field="reason" header="Reason">
                    <template #body="{ data }">
                        <span class="text-sm">{{ data.reason }}</span>
                    </template>
                </Column>
                <Column field="requested_by.name" header="Requested By" />
                <Column field="status" header="Status">
                    <template #body="{ data }">
                        <Tag :value="data.status" :severity="loadRequestStatusSeverity(data.status)" />
                        <div v-if="data.decision_note" class="text-xs mt-1" :class="isDark ? 'text-slate-500' : 'text-slate-400'">{{ data.decision_note }}</div>
                    </template>
                </Column>
                <Column v-if="canReviewLoadRequests" header="Actions">
                    <template #body="{ data }">
                        <div v-if="data.status === 'Pending'" class="flex gap-2">
                            <Button label="Approve" size="small" severity="success" @click="openReview(data, 'Approved')" />
                            <Button label="Deny" size="small" severity="danger" outlined @click="openReview(data, 'Denied')" />
                        </div>
                        <Button
                            v-else
                            icon="pi pi-trash"
                            size="small"
                            severity="danger"
                            outlined
                            aria-label="Delete request"
                            v-tooltip.top="'Delete this decided request'"
                            @click="onDeleteLoadRequest(data)"
                        />
                    </template>
                </Column>
            </DataTable>

            <template #footer>
                <Button label="Close" severity="secondary" outlined @click="loadRequestsListVisible = false" />
            </template>
        </Dialog>

        <!-- Add Faculty Dialog -->
        <Dialog
            v-model:visible="addFacultyVisible"
            modal
            :header="editingFaculty ? 'Edit Faculty' : 'Add Faculty'"
            :style="{ width: '760px', ...(isDark ? {} : { backgroundColor: 'var(--neu-card-light)' }) }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="{
                root: { class: isDark ? '!bg-[#141D33] !border !border-white/10 !text-white !rounded-2xl !shadow-2xl dark-scope' : '!border !border-[rgba(30,41,59,0.06)] !rounded-2xl !shadow-2xl' },
                header: { class: isDark ? '!bg-[#141D33] !border-b !border-white/10 !rounded-t-2xl' : '!rounded-t-2xl' },
                content: { class: isDark ? '!bg-[#141D33]' : '' },
                footer: { class: isDark ? '!bg-[#141D33] !border-t !border-white/10 !rounded-b-2xl' : '!rounded-b-2xl' },
            }"
            @hide="closeAddFaculty"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4 neu-form" @submit.prevent="onSaveFaculty">
                <!-- Faculty ID -->
                <div class="flex flex-col gap-1">
                    <label for="faculty_id" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Faculty ID <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        v-uppercase
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
                    <label for="employment_type" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Employment Type <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="employment_type"
                        v-model="facultyForm.employment_type"
                        :options="employmentTypeOptions"
                        placeholder="Select employment type"
                        :invalid="!!facultyForm.errors.employment_type"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="facultyForm.errors.employment_type" class="text-red-500">
                        {{ facultyForm.errors.employment_type }}
                    </small>
                </div>

                <!-- First Name -->
                <div class="flex flex-col gap-1">
                    <label for="first_name" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        First Name <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        v-uppercase
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
                    <label for="middle_name" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Middle Name</label>
                    <InputText
                        v-uppercase
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
                    <label for="last_name" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Last Name <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        v-uppercase
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
                    <label for="suffix" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Suffix</label>
                    <InputText
                        v-uppercase
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
                    <label for="college_id" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">College</label>
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
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
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
                    <label for="max_teaching_units" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Maximum Teaching Units <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="max_teaching_units"
                        v-model="facultyForm.max_teaching_units"
                        :min="0"
                        :max="hardCapUnits"
                        :disabled="!canChangeMaxLoad"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!facultyForm.errors.max_teaching_units"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="facultyForm.errors.max_teaching_units" class="text-red-500">
                        {{ facultyForm.errors.max_teaching_units }}
                    </small>
                    <!-- Only Admin/Registrar may raise this ceiling directly (spec: scheduling-integrity concern raised re: any role editing it freely). -->
                    <p v-if="!canChangeMaxLoad" class="text-xs text-slate-400">
                        Only Admin/Registrar can change this directly.
                        <button
                            v-if="editingFaculty"
                            type="button"
                            class="text-teal-600 hover:underline font-medium"
                            @click="openLoadRequest(editingFaculty)"
                        >
                            Request a load increase
                        </button>
                    </p>
                    <p v-else class="text-xs text-slate-400">Current teaching load ceiling: {{ hardCapUnits }} units.</p>
                </div>

                <!-- Status -->
                <div class="flex flex-col gap-1">
                    <label for="status" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
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
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    />
                    <small v-if="facultyForm.errors.status" class="text-red-500">
                        {{ facultyForm.errors.status }}
                    </small>
                </div>

                <!-- Email -->
                <div class="flex flex-col gap-1">
                    <label for="email" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Email</label>
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
                    <label for="contact_number" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Contact Number</label>
                    <InputText
                        v-uppercase
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
                    <label for="remarks" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">Remarks</label>
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

        <!-- Load Increase: Dean/OIC/Assistant Dean submit here for review (see
             FacultyLoadRequestController); Admin/Registrar use the same form
             but it applies immediately since they already have direct edit
             rights — no point routing their own change through a Pending
             request only they (or another admin) would have to approve. -->
        <Dialog
            v-model:visible="loadRequestVisible"
            modal
            :header="canReviewLoadRequests ? 'Add Load' : 'Request Load Increase'"
            :style="{ width: '28rem' }"
            :pt="{ root: { class: isDark ? 'dark-scope' : '' } }"
        >
            <p class="text-sm mb-3" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
                <template v-if="canReviewLoadRequests">
                    This updates the faculty member's teaching load ceiling immediately.
                </template>
                <template v-else>
                    This goes to an Administrator/Registrar for approval — the faculty member's ceiling won't change until they approve it.
                </template>
            </p>
            <form class="flex flex-col gap-4" @submit.prevent="onSubmitLoadRequest">
                <div v-if="!lockLoadRequestFaculty" class="flex flex-col gap-1">
                    <label class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Faculty Member <span class="text-red-500">*</span>
                    </label>
                    <Select
                        v-model="loadRequestForm.faculty_id"
                        :options="loadRequestFaculties"
                        optionLabel="last_name"
                        optionValue="id"
                        filter
                        placeholder="Select a faculty member"
                        :invalid="!!loadRequestForm.errors.faculty_id"
                        class="w-full"
                        :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                    >
                        <template #option="{ option }">
                            {{ option.last_name }}, {{ option.first_name }} — currently {{ option.max_teaching_units }} units
                        </template>
                    </Select>
                    <small v-if="loadRequestForm.errors.faculty_id" class="text-red-500">{{ loadRequestForm.errors.faculty_id }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="requested_max_teaching_units" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        {{ canReviewLoadRequests ? 'New Maximum Teaching Units' : 'Requested Maximum Teaching Units' }} <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="requested_max_teaching_units"
                        v-model="loadRequestForm.requested_max_teaching_units"
                        :min="0"
                        :max="hardCapUnits"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!loadRequestForm.errors.requested_max_teaching_units"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="loadRequestForm.errors.requested_max_teaching_units" class="text-red-500">
                        {{ loadRequestForm.errors.requested_max_teaching_units }}
                    </small>
                    <p v-else class="text-xs text-slate-400">
                        Must be higher than the current maximum<span v-if="!lockLoadRequestFaculty && loadRequestSelectedFaculty"> ({{ loadRequestSelectedFaculty.max_teaching_units }})</span>. Maximum allowed: {{ hardCapUnits }} units (set under Settings > Faculty & Workload).
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="load_request_reason" class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Reason <span class="text-red-500">*</span>
                    </label>
                    <Textarea
                        id="load_request_reason"
                        v-model="loadRequestForm.reason"
                        autoResize
                        rows="3"
                        placeholder="e.g. BSIT is short 2 faculty this semester; requesting overload to cover Networking sections."
                        :invalid="!!loadRequestForm.errors.reason"
                        class="w-full"
                    />
                    <small v-if="loadRequestForm.errors.reason" class="text-red-500">
                        {{ loadRequestForm.errors.reason }}
                    </small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeLoadRequest" />
                <Button
                    :label="canReviewLoadRequests ? 'Apply' : 'Submit Request'"
                    :icon="canReviewLoadRequests ? 'pi pi-check' : 'pi pi-send'"
                    severity="success"
                    :loading="loadRequestForm.processing"
                    @click="onSubmitLoadRequest"
                />
            </template>
        </Dialog>

        <!-- Review a Load Request (Admin/Registrar) -->
        <Dialog
            :visible="!!reviewingRequest"
            modal
            :header="reviewForm.decision === 'Approved' ? 'Approve Request' : 'Deny Request'"
            :style="{ width: '26rem' }"
            :pt="{ root: { class: isDark ? 'dark-scope' : '' } }"
            @update:visible="closeReview"
        >
            <form v-if="reviewingRequest" class="flex flex-col gap-4" @submit.prevent="onSubmitReview">
                <p class="text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
                    {{ reviewingRequest.faculty?.first_name }} {{ reviewingRequest.faculty?.last_name }} requested:
                    {{ reviewingRequest.current_max_teaching_units }} → {{ reviewingRequest.requested_max_teaching_units }} units
                </p>

                <div v-if="reviewForm.decision === 'Approved'" class="flex flex-col gap-1">
                    <label class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Approved Maximum Teaching Units
                    </label>
                    <InputNumber
                        v-model="reviewForm.approved_max_teaching_units"
                        :min="1"
                        :max="hardCapUnits"
                        showButtons
                        :invalid="!!reviewForm.errors.approved_max_teaching_units"
                        class="w-full"
                    />
                    <small v-if="reviewForm.errors.approved_max_teaching_units" class="text-red-500">
                        {{ reviewForm.errors.approved_max_teaching_units }}
                    </small>
                    <p v-else class="text-xs text-slate-400">
                        Defaults to what was requested — adjust to grant a different ceiling. Maximum allowed: {{ hardCapUnits }} units (set under Settings > Faculty & Workload).
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">
                        Note <span v-if="reviewForm.decision === 'Denied'" class="text-red-500">*</span>
                    </label>
                    <Textarea
                        v-model="reviewForm.decision_note"
                        autoResize
                        rows="3"
                        :placeholder="reviewForm.decision === 'Denied' ? 'Explain why this is being denied' : 'Optional note'"
                        :invalid="!!reviewForm.errors.decision_note"
                        class="w-full"
                    />
                    <small v-if="reviewForm.errors.decision_note" class="text-red-500">{{ reviewForm.errors.decision_note }}</small>
                </div>
            </form>
            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeReview" />
                <Button
                    :label="reviewForm.decision === 'Approved' ? 'Confirm Approve' : 'Confirm Deny'"
                    :severity="reviewForm.decision === 'Approved' ? 'success' : 'danger'"
                    :loading="reviewForm.processing"
                    @click="onSubmitReview"
                />
            </template>
        </Dialog>
    </AppLayout>
</template>

<style scoped>
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (added conditionally via isDark) recolors the shared light-mode
   Tailwind utility classes and PrimeVue component chrome used
   throughout this page. The Dialog is teleported to <body> by
   PrimeVue, so its rules use :global() with a compound selector
   instead of :deep() — Vue's scoped-CSS attribute doesn't reliably
   travel through the teleport boundary. */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-card) { background: #101A35 !important; color: #F8FAFC; border: 1px solid rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }

.dark-scope :deep(.p-datatable) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.06) !important; color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.12) !important; font-weight: 600; }
.dark-scope :deep(.p-datatable-tbody > tr) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-tbody > tr > td) { color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped) { background: rgba(255, 255, 255, 0.035) !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped > td) { background: transparent !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover) { background: rgba(255, 255, 255, 0.07) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover > td) { background: transparent !important; }
.dark-scope :deep(.p-datatable-emptymessage) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-paginator) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-paginator .p-paginator-page),
.dark-scope :deep(.p-paginator .p-paginator-prev),
.dark-scope :deep(.p-paginator .p-paginator-next),
.dark-scope :deep(.p-paginator .p-paginator-first),
.dark-scope :deep(.p-paginator .p-paginator-last) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-paginator .p-paginator-page.p-paginator-page-selected) { background: rgba(37, 99, 235, 0.9) !important; color: #fff !important; }

.dark-scope :deep(.p-button-text.p-button-secondary) { color: #CBD5E1 !important; }
.dark-scope :deep(.p-button-text.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-button-text.p-button-danger) { color: #FCA5A5 !important; }
.dark-scope :deep(.p-button-text.p-button-danger:hover) { background: rgba(248, 113, 113, 0.12) !important; color: #FECACA !important; }
.dark-scope :deep(.p-button-text.p-button-info) { color: #7DD3FC !important; }
.dark-scope :deep(.p-button-text.p-button-info:hover) { background: rgba(56, 189, 248, 0.12) !important; color: #BAE6FD !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary) { color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.2) !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; }

/* Add / Edit Faculty modal (teleported to <body>) */
:global(.dark-scope.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-title) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog .p-dialog-close-button:hover) { background: rgba(255, 255, 255, 0.08) !important; color: #F8FAFC !important; }

:global(.dark-scope.p-dialog label) { color: #CBD5E1 !important; }
:global(.dark-scope.p-dialog p.text-slate-400) { color: #94A3B8 !important; }
:global(.dark-scope.p-dialog .p-inputtext),
:global(.dark-scope.p-dialog .p-textarea),
:global(.dark-scope.p-dialog .p-select),
:global(.dark-scope.p-dialog .p-multiselect),
:global(.dark-scope.p-dialog .p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.18) !important;
    color: #F8FAFC !important;
}
:global(.dark-scope.p-dialog .p-inputtext::placeholder),
:global(.dark-scope.p-dialog .p-textarea::placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-select-label),
:global(.dark-scope.p-dialog .p-multiselect-label) { color: #F8FAFC !important; }
:global(.dark-scope.p-dialog .p-select-label.p-placeholder),
:global(.dark-scope.p-dialog .p-multiselect-label.p-placeholder) { color: #7C8CA8 !important; }
:global(.dark-scope.p-dialog .p-inputnumber-button) { background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(255, 255, 255, 0.18) !important; color: #F8FAFC !important; }

:global(.p-select-overlay.dark-scope),
:global(.p-multiselect-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-select-overlay.dark-scope .p-select-option),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option) { color: #F1F5F9 !important; }
:global(.p-select-overlay.dark-scope .p-select-option:hover),
:global(.p-multiselect-overlay.dark-scope .p-multiselect-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
</style>