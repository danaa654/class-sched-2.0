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
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import FloatLabel from 'primevue/floatlabel';
import Toast from 'primevue/toast';
import Checkbox from 'primevue/checkbox';

const props = defineProps({
    academicTerms: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    filters: {
        type: Object,
        default: () => ({ academic_term_search: '' }),
    },
    schedulingSettingsOptions: {
        type: Object,
        default: () => ({
            days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            default_days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            default_class_start_time: '07:00',
            default_class_end_time: '17:00',
            lunch_break_start: '12:00',
            lunch_break_end: '13:00',
        }),
    },
});

// Full day names for the "Class Days" checkboxes — keyed by the short
// Day token the backend stores (available_days column and the
// scheduling engine's Day tokens everywhere else in the app).
const dayLabels = {
    Mon: 'Monday',
    Tue: 'Tuesday',
    Wed: 'Wednesday',
    Thu: 'Thursday',
    Fri: 'Friday',
    Sat: 'Saturday',
    Sun: 'Sunday',
};

// 12-hour display helper for the read-only Lunch Break/Time Interval
// blocks and for building the Class Start/End Time dropdown labels.
const formatTimeLabel = (time) => {
    if (!time) return '—';
    const [hour, minute] = time.split(':').map(Number);
    const period = hour >= 12 ? 'PM' : 'AM';
    const displayHour = hour % 12 === 0 ? 12 : hour % 12;
    return `${displayHour}:${String(minute).padStart(2, '0')} ${period}`;
};

const lunchBreakLabel = computed(
    () => `${formatTimeLabel(props.schedulingSettingsOptions.lunch_break_start)} – ${formatTimeLabel(props.schedulingSettingsOptions.lunch_break_end)}`,
);

// Class Start Time / Class End Time dropdown options — every
// half-hour mark from 5:00 AM to 9:00 PM (e.g. "7:00 AM", "7:30 AM",
// "8:00 AM", ...), as "H:i" values so they save straight to the
// School Year's class_start_time / class_end_time columns.
const buildTimeOptions = (startHour, endHour) => {
    const options = [];
    for (let minutes = startHour * 60; minutes <= endHour * 60; minutes += 30) {
        const hour = String(Math.floor(minutes / 60)).padStart(2, '0');
        const minute = String(minutes % 60).padStart(2, '0');
        const value = `${hour}:${minute}`;
        options.push({ label: formatTimeLabel(value), value });
    }
    return options;
};

const classTimeOptions = buildTimeOptions(5, 21);

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

// Semester dropdown — a fixed list, not a lookup table. There's no
// Semester picker/CRUD screen anymore; the backend creates the
// matching Semester record behind the scenes the first time each one
// is actually used (see AcademicTermController@resolveSemester), so
// nothing needs to be seeded for this to work.
const semesterOptions = [
    { label: '1st Semester', value: '1st Semester' },
    { label: '2nd Semester', value: '2nd Semester' },
    { label: 'Summer', value: 'Summer' },
];

/* ------------------------------------------------------------------ */
/* Academic Terms                                                      */
/*                                                                      */
/* This is the single place School Year (Start Year/End Year),         */
/* Semester, Status, Remarks, and Scheduling Preferences are all       */
/* entered together — there is no separate School Year or Semester    */
/* tab on this page anymore. The School Year and Semester records      */
/* themselves are untouched under the hood (still used everywhere      */
/* else in the app); saving an Academic Term just finds-or-creates the */
/* matching School Year from Start Year/End Year behind the scenes.    */
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
    start_year: null,
    end_year: null,
    semester: null,
    status: 'Active',
    remarks: '',
    // Scheduling Preferences — used by the Auto Schedule AI. Time
    // Interval is fixed at 30 Minutes (see the locked "Time Interval"
    // row in the template) so it isn't part of this form's payload.
    class_start_time: props.schedulingSettingsOptions.default_class_start_time,
    class_end_time: props.schedulingSettingsOptions.default_class_end_time,
    available_days: [...props.schedulingSettingsOptions.default_days],
});

// Read-only preview of the derived School Year name (e.g. 2026-2027).
// The user never types this directly — it's derived from Start Year/
// End Year, same as the old standalone School Year form.
const schoolYearNamePreview = computed(() => {
    if (!academicTermForm.start_year || !academicTermForm.end_year) {
        return '—';
    }

    return `${academicTermForm.start_year}-${academicTermForm.end_year}`;
});

// Keep End Year one year ahead of Start Year automatically, so the
// common case needs no extra typing (the user can still override it).
watch(
    () => academicTermForm.start_year,
    (newStartYear, oldStartYear) => {
        if (newStartYear === oldStartYear) {
            return;
        }

        if (newStartYear && (!academicTermForm.end_year || academicTermForm.end_year === oldStartYear + 1)) {
            academicTermForm.end_year = newStartYear + 1;
        }
    },
);

const openAddAcademicTerm = () => {
    academicTermDialogMode.value = 'add';
    editingAcademicTerm.value = null;
    academicTermForm.reset();
    academicTermForm.clearErrors();
    academicTermForm.status = 'Active';
    academicTermForm.class_start_time = props.schedulingSettingsOptions.default_class_start_time;
    academicTermForm.class_end_time = props.schedulingSettingsOptions.default_class_end_time;
    academicTermForm.available_days = [...props.schedulingSettingsOptions.default_days];
    academicTermDialogVisible.value = true;
};

const openEditAcademicTerm = (academicTerm) => {
    academicTermDialogMode.value = 'edit';
    editingAcademicTerm.value = academicTerm;
    academicTermForm.clearErrors();
    academicTermForm.start_year = academicTerm.school_year?.start_year ?? null;
    academicTermForm.end_year = academicTerm.school_year?.end_year ?? null;
    academicTermForm.semester = academicTerm.semester?.name ?? null;
    academicTermForm.status = academicTerm.status;
    academicTermForm.remarks = academicTerm.remarks ?? '';
    academicTermForm.class_start_time = academicTerm.school_year?.class_start_time?.slice(0, 5) ?? props.schedulingSettingsOptions.default_class_start_time;
    academicTermForm.class_end_time = academicTerm.school_year?.class_end_time?.slice(0, 5) ?? props.schedulingSettingsOptions.default_class_end_time;
    academicTermForm.available_days = academicTerm.school_year?.available_days?.length
        ? [...academicTerm.school_year.available_days]
        : [...props.schedulingSettingsOptions.default_days];
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
                    Manage academic terms — School Year, Semester, and Scheduling Preferences all in one place.
                </p>
            </div>

            <!-- Academic Terms -->
            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
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
                        <Column header="Scheduling Window" style="width: 16rem">
                            <template #body="{ data }">
                                <span class="text-slate-500 text-sm">
                                    {{ formatTimeLabel(data.school_year?.class_start_time?.slice(0, 5)) }} – {{ formatTimeLabel(data.school_year?.class_end_time?.slice(0, 5)) }}
                                </span>
                                <div class="text-xs text-slate-400 mt-0.5">
                                    {{ (data.school_year?.available_days ?? []).join(', ') || '—' }}
                                </div>
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
        </div>

        <!-- Add / Edit Academic Term Modal -->
        <Dialog
            v-model:visible="academicTermDialogVisible"
            modal
            :style="{ width: '620px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
        >
            <template #header>
                <span class="text-lg font-bold text-[#1E293B]">
                    {{ academicTermDialogMode === 'add' ? 'Add Academic Term' : 'Edit Academic Term' }}
                </span>
            </template>

            <form class="pt-2" autocomplete="off" @submit.prevent="onSaveAcademicTerm">
                <!-- School Year -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <FloatLabel variant="on">
                        <InputNumber
                            id="academicTermStartYear"
                            v-model="academicTermForm.start_year"
                            class="w-full"
                            :useGrouping="false"
                            :invalid="!!academicTermForm.errors.start_year"
                        />
                        <label for="academicTermStartYear">Start Year *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <InputNumber
                            id="academicTermEndYear"
                            v-model="academicTermForm.end_year"
                            class="w-full"
                            :useGrouping="false"
                            :invalid="!!academicTermForm.errors.end_year"
                        />
                        <label for="academicTermEndYear">End Year *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-1">
                    <small v-if="academicTermForm.errors.start_year" class="text-red-500">{{ academicTermForm.errors.start_year }}</small>
                    <small v-if="academicTermForm.errors.end_year" class="text-red-500">{{ academicTermForm.errors.end_year }}</small>
                </div>

                <!-- Read-only preview of the derived School Year name -->
                <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">School Year</p>
                    <p class="text-lg font-bold text-[#1E293B]">{{ schoolYearNamePreview }}</p>
                </div>

                <!-- Semester -->
                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Select
                            id="academicTermSemester"
                            v-model="academicTermForm.semester"
                            :options="semesterOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!academicTermForm.errors.semester"
                        />
                        <label for="academicTermSemester">Semester *</label>
                    </FloatLabel>
                    <small v-if="academicTermForm.errors.semester" class="text-red-500 -mt-4">{{ academicTermForm.errors.semester }}</small>
                </div>

                <!-- Status -->
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

                <!-- Remarks -->
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

                <!-- Scheduling Preferences — used by the Auto Schedule AI -->
                <div class="mt-6 pt-5 border-t border-slate-100">
                    <p class="text-sm font-bold text-[#1E293B]">Scheduling Preferences</p>
                    <p class="text-xs text-slate-400 mt-1">
                        These rules are used by the Auto Schedule AI when generating class schedules for this School Year.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Select
                            id="academicTermClassStartTime"
                            v-model="academicTermForm.class_start_time"
                            :options="classTimeOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!academicTermForm.errors.class_start_time"
                        />
                        <label for="academicTermClassStartTime">Class Start Time *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Select
                            id="academicTermClassEndTime"
                            v-model="academicTermForm.class_end_time"
                            :options="classTimeOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!academicTermForm.errors.class_end_time"
                        />
                        <label for="academicTermClassEndTime">Class End Time *</label>
                    </FloatLabel>
                </div>
                <div class="grid grid-cols-2 gap-1 mt-1">
                    <small v-if="academicTermForm.errors.class_start_time" class="text-red-500">{{ academicTermForm.errors.class_start_time }}</small>
                    <small v-if="academicTermForm.errors.class_end_time" class="text-red-500">{{ academicTermForm.errors.class_end_time }}</small>
                </div>

                <!-- Lunch Break — locked information row, never editable -->
                <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-700">Lunch Break</span>
                        <i class="pi pi-lock text-slate-400 text-xs" title="Not editable"></i>
                    </div>
                    <p class="text-sm text-slate-500 mt-1">{{ lunchBreakLabel }}</p>
                    <p class="text-xs text-slate-400 mt-1">
                        The Auto Schedule AI will never generate a class that overlaps this period.
                    </p>
                </div>

                <!-- Time Interval — locked information row, always 30 Minutes -->
                <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-700">Time Interval</span>
                        <i class="pi pi-lock text-slate-400 text-xs" title="Not editable"></i>
                    </div>
                    <p class="text-sm text-slate-500 mt-1">30 Minutes</p>
                    <p class="text-xs text-slate-400 mt-1">
                        The Auto Schedule AI slices the day into 30-minute slots when generating class schedules.
                    </p>
                </div>

                <div class="mt-5">
                    <label class="text-sm font-medium text-slate-700">Class Days *</label>
                    <div class="grid grid-cols-2 gap-3 mt-2">
                        <div v-for="day in props.schedulingSettingsOptions.days" :key="day" class="flex items-center gap-2">
                            <Checkbox
                                v-model="academicTermForm.available_days"
                                :inputId="`academicTermDay_${day}`"
                                :value="day"
                            />
                            <label :for="`academicTermDay_${day}`" class="text-sm text-slate-600">{{ dayLabels[day] }}</label>
                        </div>
                    </div>
                    <small v-if="academicTermForm.errors.available_days" class="text-red-500 block mt-2">{{ academicTermForm.errors.available_days }}</small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="academicTermForm.processing" @click="closeAcademicTermDialog" />
                <Button label="Save" icon="pi pi-check" :loading="academicTermForm.processing" @click="onSaveAcademicTerm" />
            </template>
        </Dialog>
    </AppLayout>
</template>