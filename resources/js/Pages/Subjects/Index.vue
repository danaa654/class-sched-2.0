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
    subjects: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    filters: {
        type: Object,
        default: () => ({ subject_search: '' }),
    },
    majors: { type: Array, default: () => [] },
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

const search = ref(props.filters.subject_search ?? '');
const loading = ref(false);
let searchDebounce = null;

const reloadSubjects = (extra = {}) => {
    loading.value = true;

    router.get(
        route('subjects'),
        { subject_search: search.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['subjects'],
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        reloadSubjects({ subject_page: 1 });
    }, 350);
});

const onPage = (event) => {
    reloadSubjects({ subject_page: event.page + 1 });
};

const onRefresh = () => {
    reloadSubjects({ subject_page: props.subjects.current_page });
};

/* ------------------------------------------------------------------ */
/* Add Subject                                                         */
/* ------------------------------------------------------------------ */

const categoryOptions = ['Major', 'General Education'];
const statusOptions = [
    { label: 'Active', value: true },
    { label: 'Inactive', value: false },
];

const addSubjectVisible = ref(false);

const subjectForm = useForm({
    subject_code: '',
    subject_title: '',
    major_id: null,
    category: null,
    units: 0,
    lecture_hours: 0,
    laboratory_hours: 0,
    is_active: true,
    description: '',
});

// Major is only meaningful when Subject Type is "Major".
watch(
    () => subjectForm.category,
    (category) => {
        if (category === 'General Education') {
            subjectForm.major_id = null;
        }
    },
);

const openAdd = () => {
    subjectForm.reset();
    subjectForm.clearErrors();
    addSubjectVisible.value = true;
};

const closeAddSubject = () => {
    addSubjectVisible.value = false;
    subjectForm.reset();
    subjectForm.clearErrors();
};

const onSaveSubject = () => {
    subjectForm.post(route('subjects.store'), {
        preserveScroll: true,
        onSuccess: () => {
            closeAddSubject();
            Swal.fire({
                title: 'Subject saved',
                text: 'The subject was created successfully.',
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
    });
};
</script>

<template>
    <Head title="Subjects" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Subjects</span>
        </template>

        <div class="max-w-7xl mx-auto w-full">
            <!-- Page Title -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">Subjects</h1>
                <p class="mt-1 text-slate-500">
                    Manage the master list of all subjects offered by the institution.
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
                                    placeholder="Search by code, title, category or major"
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
                                <Button label="Add Subject" icon="pi pi-plus" severity="success" @click="openAdd" />
                            </div>
                        </template>
                    </Toolbar>

                    <!-- Subjects Table -->
                    <DataTable
                        :value="subjects.data"
                        :loading="loading"
                        dataKey="id"
                        class="rounded-xl overflow-hidden"
                        stripedRows
                        responsiveLayout="scroll"
                        lazy
                        paginator
                        :rows="subjects.per_page"
                        :totalRecords="subjects.total"
                        :first="(subjects.current_page - 1) * subjects.per_page"
                        @page="onPage"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No subjects found.</p>
                                <p class="text-slate-400 text-sm mt-1">
                                    Click "Add Subject" to create your first subject.
                                </p>
                                <Button
                                    label="Add Subject"
                                    icon="pi pi-plus"
                                    severity="success"
                                    class="mt-3"
                                    @click="openAdd"
                                />
                            </div>
                        </template>

                        <Column field="subject_code" header="Subject Code" style="width: 10rem" />
                        <Column field="subject_title" header="Subject Title" />
                        <Column header="Category" style="width: 11rem">
                            <template #body="{ data }">
                                {{ data.category }}
                            </template>
                        </Column>
                        <Column header="Major" style="width: 14rem">
                            <template #body="{ data }">
                                {{ data.major?.name || '—' }}
                            </template>
                        </Column>
                        <Column header="Units" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.units }}
                            </template>
                        </Column>
                        <Column header="Lecture Hours" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.lecture_hours }}
                            </template>
                        </Column>
                        <Column header="Lab Hours" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.laboratory_hours }}
                            </template>
                        </Column>
                        <Column header="Status" style="width: 9rem">
                            <template #body="{ data }">
                                <Tag
                                    :value="data.is_active ? 'Active' : 'Inactive'"
                                    :severity="data.is_active ? 'success' : 'secondary'"
                                />
                            </template>
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
                                        @click="openAdd"
                                    />
                                    <Button
                                        icon="pi pi-trash"
                                        text
                                        rounded
                                        severity="danger"
                                        size="small"
                                        aria-label="Delete"
                                        @click="openAdd"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>

        <!-- Add Subject Dialog -->
        <Dialog
            v-model:visible="addSubjectVisible"
            modal
            header="Add Subject"
            :style="{ width: '700px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            @hide="closeAddSubject"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4" @submit.prevent="onSaveSubject">
                <!-- Subject Code -->
                <div class="flex flex-col gap-1">
                    <label for="subject_code" class="text-sm font-medium text-slate-700">
                        Subject Code <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="subject_code"
                        v-model="subjectForm.subject_code"
                        placeholder="e.g. IT101, GE103, PE1, NSTP2"
                        :invalid="!!subjectForm.errors.subject_code"
                        class="w-full"
                    />
                    <small v-if="subjectForm.errors.subject_code" class="text-red-500">
                        {{ subjectForm.errors.subject_code }}
                    </small>
                </div>

                <!-- Subject Title -->
                <div class="flex flex-col gap-1">
                    <label for="subject_title" class="text-sm font-medium text-slate-700">
                        Subject Title <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="subject_title"
                        v-model="subjectForm.subject_title"
                        placeholder="e.g. Introduction to Computing"
                        :invalid="!!subjectForm.errors.subject_title"
                        class="w-full"
                    />
                    <small v-if="subjectForm.errors.subject_title" class="text-red-500">
                        {{ subjectForm.errors.subject_title }}
                    </small>
                </div>

                <!-- Category -->
                <div class="flex flex-col gap-1">
                    <label for="category" class="text-sm font-medium text-slate-700">
                        Category <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="category"
                        v-model="subjectForm.category"
                        :options="categoryOptions"
                        placeholder="Select a category"
                        :invalid="!!subjectForm.errors.category"
                        class="w-full"
                    />
                    <small v-if="subjectForm.errors.category" class="text-red-500">
                        {{ subjectForm.errors.category }}
                    </small>
                </div>

                <!-- Major -->
                <div class="flex flex-col gap-1">
                    <label for="major_id" class="text-sm font-medium text-slate-700">
                        Major
                        <span v-if="subjectForm.category === 'Major'" class="text-red-500">*</span>
                    </label>
                    <Select
                        id="major_id"
                        v-model="subjectForm.major_id"
                        :options="majors"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Select a major"
                        showClear
                        :disabled="subjectForm.category !== 'Major'"
                        :invalid="!!subjectForm.errors.major_id"
                        class="w-full"
                    />
                    <small v-if="subjectForm.category === 'General Education'" class="text-slate-400">
                        Not applicable for General Education subjects.
                    </small>
                    <small v-else-if="subjectForm.errors.major_id" class="text-red-500">
                        {{ subjectForm.errors.major_id }}
                    </small>
                </div>

                <!-- Units -->
                <div class="flex flex-col gap-1">
                    <label for="units" class="text-sm font-medium text-slate-700">
                        Units <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="units"
                        v-model="subjectForm.units"
                        :min="0"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!subjectForm.errors.units"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="subjectForm.errors.units" class="text-red-500">
                        {{ subjectForm.errors.units }}
                    </small>
                </div>

                <!-- Status -->
                <div class="flex flex-col gap-1">
                    <label for="is_active" class="text-sm font-medium text-slate-700">
                        Status <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="is_active"
                        v-model="subjectForm.is_active"
                        :options="statusOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select status"
                        :invalid="!!subjectForm.errors.is_active"
                        class="w-full"
                    />
                    <small v-if="subjectForm.errors.is_active" class="text-red-500">
                        {{ subjectForm.errors.is_active }}
                    </small>
                </div>

                <!-- Lecture Hours -->
                <div class="flex flex-col gap-1">
                    <label for="lecture_hours" class="text-sm font-medium text-slate-700">
                        Lecture Hours <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="lecture_hours"
                        v-model="subjectForm.lecture_hours"
                        :min="0"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!subjectForm.errors.lecture_hours"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="subjectForm.errors.lecture_hours" class="text-red-500">
                        {{ subjectForm.errors.lecture_hours }}
                    </small>
                </div>

                <!-- Laboratory Hours -->
                <div class="flex flex-col gap-1">
                    <label for="laboratory_hours" class="text-sm font-medium text-slate-700">
                        Laboratory Hours <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="laboratory_hours"
                        v-model="subjectForm.laboratory_hours"
                        :min="0"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!subjectForm.errors.laboratory_hours"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="subjectForm.errors.laboratory_hours" class="text-red-500">
                        {{ subjectForm.errors.laboratory_hours }}
                    </small>
                </div>

                <!-- Description -->
                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label for="description" class="text-sm font-medium text-slate-700">Description</label>
                    <Textarea
                        id="description"
                        v-model="subjectForm.description"
                        autoResize
                        rows="3"
                        placeholder="Optional notes about this subject"
                        :invalid="!!subjectForm.errors.description"
                        class="w-full"
                    />
                    <small v-if="subjectForm.errors.description" class="text-red-500">
                        {{ subjectForm.errors.description }}
                    </small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeAddSubject" />
                <Button
                    label="Save Subject"
                    icon="pi pi-check"
                    severity="success"
                    :loading="subjectForm.processing"
                    @click="onSaveSubject"
                />
            </template>
        </Dialog>
    </AppLayout>
</template>