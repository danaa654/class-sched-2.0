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
import Textarea from 'primevue/textarea';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import Checkbox from 'primevue/checkbox';
import FloatLabel from 'primevue/floatlabel';
import Toast from 'primevue/toast';

const props = defineProps({
    curriculums: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    activeMajors: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ curriculum_search: '' }),
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
    { label: 'Draft', value: 'Draft' },
    { label: 'Active', value: 'Active' },
    { label: 'Archived', value: 'Archived' },
];

const statusSeverity = (status) => {
    if (status === 'Active') return 'success';
    if (status === 'Archived') return 'warn';
    return 'secondary'; // Draft
};

const majorOptions = computed(() =>
    [...props.activeMajors]
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((major) => ({ label: major.name, value: major.id })),
);

/* ------------------------------------------------------------------ */
/* Search / list                                                       */
/* ------------------------------------------------------------------ */

const search = ref(props.filters.curriculum_search ?? '');
const loading = ref(false);
let searchDebounce = null;

const reloadCurriculums = (extra = {}) => {
    loading.value = true;

    router.get(
        route('curriculums'),
        { curriculum_search: search.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['curriculums'],
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        reloadCurriculums({ curriculum_page: 1 });
    }, 350);
});

const onPage = (event) => {
    reloadCurriculums({ curriculum_page: event.page + 1 });
};

const onRefresh = () => {
    reloadCurriculums({ curriculum_page: props.curriculums.current_page });
};

/* ------------------------------------------------------------------ */
/* Add / Edit Curriculum dialog                                        */
/* ------------------------------------------------------------------ */

const dialogVisible = ref(false);
const dialogMode = ref('add'); // 'add' | 'edit'
const editingCurriculum = ref(null);

// Tracks whether Code / Name have been hand-edited by the user, so
// auto-generation doesn't clobber a deliberate override once they've
// started typing their own value.
const codeTouched = ref(false);
const nameTouched = ref(false);

const form = useForm({
    major_id: null,
    code: '',
    name: '',
    start_year: null,
    status: 'Draft',
    allow_new_students: true,
    description: '',
});

const selectedMajor = computed(() =>
    props.activeMajors.find((major) => major.id === form.major_id) ?? null,
);

// The Curriculum always spans 4 years from the chosen Start Year —
// this is display-only; the authoritative value is computed server-side.
const CURRICULUM_DURATION_YEARS = 4;

const endYear = computed(() => {
    if (!form.start_year) {
        return null;
    }
    return form.start_year + CURRICULUM_DURATION_YEARS;
});

const yearRangeLabel = computed(() =>
    form.start_year && endYear.value ? `${form.start_year} – ${endYear.value}` : null,
);

// Auto-generate Curriculum Code and Name from Major + Start Year.
// Example: Major "BS Information Technology" (code BSIT) + 2023
//   -> Code: BSIT-2023
//   -> Name: BS Information Technology Curriculum (2023–2027)
const applyAutoGeneration = () => {
    if (!selectedMajor.value || !form.start_year) {
        return;
    }

    if (!codeTouched.value) {
        form.code = `${selectedMajor.value.code}-${form.start_year}`;
    }

    if (!nameTouched.value) {
        form.name = `${selectedMajor.value.name} Curriculum (${form.start_year}–${endYear.value})`;
    }
};

watch(() => form.major_id, applyAutoGeneration);
watch(() => form.start_year, applyAutoGeneration);

const onCodeInput = () => {
    codeTouched.value = true;
};
const onNameInput = () => {
    nameTouched.value = true;
};

const openAdd = () => {
    dialogMode.value = 'add';
    editingCurriculum.value = null;
    codeTouched.value = false;
    nameTouched.value = false;
    form.reset();
    form.clearErrors();
    form.status = 'Draft';
    form.allow_new_students = true;
    dialogVisible.value = true;
};

const openEdit = (curriculum) => {
    dialogMode.value = 'edit';
    editingCurriculum.value = curriculum;
    // Editing an existing record always shows its saved Code/Name —
    // auto-generation shouldn't silently overwrite them.
    codeTouched.value = true;
    nameTouched.value = true;
    form.clearErrors();
    form.major_id = curriculum.major_id;
    form.code = curriculum.code;
    form.name = curriculum.name;
    form.start_year = curriculum.start_year;
    form.status = curriculum.status;
    form.allow_new_students = !!curriculum.allow_new_students;
    form.description = curriculum.description ?? '';
    dialogVisible.value = true;
};

const closeDialog = () => {
    dialogVisible.value = false;
    form.reset();
    form.clearErrors();
    editingCurriculum.value = null;
};

const onSave = () => {
    const onError = () => {
        toast.add({
            severity: 'warn',
            summary: 'Missing information',
            detail: 'Please check the highlighted fields and try again.',
            life: 3000,
        });
    };

    if (dialogMode.value === 'add') {
        form.post(route('curriculums.store'), {
            preserveScroll: true,
            onSuccess: () => closeDialog(),
            onError,
        });
    } else {
        form.put(route('curriculums.update', editingCurriculum.value.id), {
            preserveScroll: true,
            onSuccess: () => closeDialog(),
            onError,
        });
    }
};

const onDelete = (curriculum) => {
    Swal.fire({
        title: 'Are you sure you want to delete this curriculum?',
        text: curriculum.name,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('curriculums.destroy', curriculum.id), {
                preserveScroll: true,
                preserveState: true,
            });
        }
    });
};

const onRestore = (curriculum) => {
    router.put(route('curriculums.restore', curriculum.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
};
</script>

<template>
    <Head title="Curriculum" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Curriculum</span>
        </template>

        <div class="max-w-7xl mx-auto w-full">
            <!-- Page Title -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">Curriculum</h1>
                <p class="mt-1 text-slate-500">
                    Manage the academic plans (subjects offered per Major) used across school years.
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
                                    placeholder="Search by code, name, major or start/end year"
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
                                <Button label="Add Curriculum" icon="pi pi-plus" @click="openAdd" />
                            </div>
                        </template>
                    </Toolbar>

                    <!-- Curriculums Table -->
                    <DataTable
                        :value="curriculums.data"
                        :loading="loading"
                        dataKey="id"
                        class="rounded-xl overflow-hidden"
                        stripedRows
                        responsiveLayout="scroll"
                        lazy
                        paginator
                        :rows="curriculums.per_page"
                        :totalRecords="curriculums.total"
                        :first="(curriculums.current_page - 1) * curriculums.per_page"
                        @page="onPage"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No curriculums found.</p>
                                <Button
                                    label="Add Curriculum"
                                    icon="pi pi-plus"
                                    class="mt-3"
                                    @click="openAdd"
                                />
                            </div>
                        </template>

                        <Column field="code" header="Code" style="width: 10rem" />
                        <Column field="name" header="Curriculum" />
                        <Column header="Major" style="width: 16rem">
                            <template #body="{ data }">
                                {{ data.major?.name || '—' }}
                            </template>
                        </Column>
                        <Column header="Duration" style="width: 10rem">
                            <template #body="{ data }">
                                {{ data.start_year }} - {{ data.end_year }}
                            </template>
                        </Column>
                        <Column header="Status" style="width: 9rem">
                            <template #body="{ data }">
                                <Tag v-if="data.deleted_at" value="Deleted" severity="danger" />
                                <Tag v-else :value="data.status" :severity="statusSeverity(data.status)" />
                            </template>
                        </Column>
                        <Column header="Allow New Students" style="width: 10rem">
                            <template #body="{ data }">
                                <Tag
                                    :value="data.allow_new_students ? 'Yes' : 'No'"
                                    :severity="data.allow_new_students ? 'success' : 'secondary'"
                                />
                            </template>
                        </Column>
                        <Column header="Actions" style="width: 16rem">
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <template v-if="!data.deleted_at">
                                        <Button
                                            icon="pi pi-list"
                                            label="Manage Subjects"
                                            text
                                            severity="info"
                                            size="small"
                                            @click="router.visit(route('curriculums.subjects', data.id))"
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
                                            @click="onDelete(data)"
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
                                        @click="onRestore(data)"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>

        <!-- Add / Edit Curriculum Modal -->
        <Dialog
            v-model:visible="dialogVisible"
            modal
            :style="{ width: '700px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
        >
            <template #header>
                <span class="text-lg font-bold text-[#1E293B]">
                    {{ dialogMode === 'add' ? 'Add Curriculum' : 'Edit Curriculum' }}
                </span>
            </template>

            <form class="pt-2" autocomplete="off" @submit.prevent="onSave">
                <!-- Major -->
                <div class="grid grid-cols-1 gap-5">
                    <FloatLabel variant="on">
                        <Select
                            id="curriculumMajor"
                            v-model="form.major_id"
                            :options="majorOptions"
                            optionLabel="label"
                            optionValue="value"
                            filter
                            class="w-full"
                            :invalid="!!form.errors.major_id"
                        />
                        <label for="curriculumMajor">Major *</label>
                    </FloatLabel>
                    <small v-if="form.errors.major_id" class="text-red-500 -mt-4">{{ form.errors.major_id }}</small>
                </div>

                <!-- Start Year -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputNumber
                            id="curriculumStartYear"
                            v-model="form.start_year"
                            class="w-full"
                            :useGrouping="false"
                            :min="2000"
                            :max="2100"
                            :invalid="!!form.errors.start_year"
                        />
                        <label for="curriculumStartYear">Start Year *</label>
                    </FloatLabel>

                    <div v-if="yearRangeLabel" class="flex flex-col justify-center px-3 py-1 rounded-lg bg-emerald-50 border border-emerald-100">
                        <span class="text-[11px] font-medium uppercase tracking-wide text-emerald-600">Curriculum Duration</span>
                        <span class="text-sm font-semibold text-emerald-700">{{ yearRangeLabel }}</span>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-1 mt-1">
                    <small v-if="form.errors.start_year" class="text-red-500">{{ form.errors.start_year }}</small>
                    <small v-if="form.errors.end_year" class="text-red-500">{{ form.errors.end_year }}</small>
                </div>

                <!-- Curriculum Code / Curriculum Name (auto-generated, editable) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="curriculumCode"
                            v-model="form.code"
                            class="w-full"
                            maxlength="50"
                            autocomplete="off"
                            :invalid="!!form.errors.code"
                            @input="onCodeInput"
                        />
                        <label for="curriculumCode">Curriculum Code *</label>
                    </FloatLabel>

                    <FloatLabel variant="on">
                        <Select
                            id="curriculumStatus"
                            v-model="form.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :invalid="!!form.errors.status"
                        />
                        <label for="curriculumStatus">Status *</label>
                    </FloatLabel>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Code and Name are auto-generated from Major + Start Year — feel free to change them.
                </p>
                <small v-if="form.errors.code" class="text-red-500 block">{{ form.errors.code }}</small>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <InputText
                            id="curriculumName"
                            v-model="form.name"
                            class="w-full"
                            maxlength="255"
                            autocomplete="off"
                            :invalid="!!form.errors.name"
                            @input="onNameInput"
                        />
                        <label for="curriculumName">Curriculum Name *</label>
                    </FloatLabel>
                    <small v-if="form.errors.name" class="text-red-500 -mt-4">{{ form.errors.name }}</small>
                </div>

                <!-- Allow New Students -->
                <div class="flex items-center gap-2 mt-5">
                    <Checkbox
                        v-model="form.allow_new_students"
                        inputId="curriculumAllowNewStudents"
                        binary
                    />
                    <label for="curriculumAllowNewStudents" class="text-sm text-[#1E293B]">
                        Allow New Students
                    </label>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    When disabled, only existing or irregular students may continue using this curriculum.
                </p>

                <div class="grid grid-cols-1 gap-5 mt-5">
                    <FloatLabel variant="on">
                        <Textarea
                            id="curriculumDescription"
                            v-model="form.description"
                            class="w-full"
                            rows="3"
                            autoResize
                            :invalid="!!form.errors.description"
                        />
                        <label for="curriculumDescription">Description</label>
                    </FloatLabel>
                    <small v-if="form.errors.description" class="text-red-500 -mt-4">{{ form.errors.description }}</small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="form.processing" @click="closeDialog" />
                <Button label="Save" icon="pi pi-check" :loading="form.processing" @click="onSave" />
            </template>
        </Dialog>
    </AppLayout>
</template>