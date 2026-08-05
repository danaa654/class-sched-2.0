<script setup>
import { Head, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import MultiSelect from 'primevue/multiselect';
import Toast from 'primevue/toast';

const props = defineProps({
    faculties: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ faculty_search: '' }),
    },
    selectedFaculty: { type: Object, default: null },
    subjects: { type: Array, default: () => [] },
});

const toast = useToast();
const page = usePage();

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
/* Faculty list (left panel)                                           */
/* ------------------------------------------------------------------ */

const search = ref(props.filters.faculty_search ?? '');
const loadingList = ref(false);
let searchDebounce = null;

const reloadFaculties = () => {
    loadingList.value = true;

    router.get(
        route('scheduling.teaching-qualifications'),
        {
            faculty_search: search.value,
            faculty_id: props.selectedFaculty?.id ?? undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['faculties', 'filters'],
            onFinish: () => {
                loadingList.value = false;
            },
        },
    );
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(reloadFaculties, 350);
});

const loadingFaculty = ref(false);

const selectFaculty = (faculty) => {
    if (faculty.id === props.selectedFaculty?.id) {
        return;
    }

    loadingFaculty.value = true;

    router.get(
        route('scheduling.teaching-qualifications'),
        { faculty_search: search.value, faculty_id: faculty.id },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['selectedFaculty'],
            onFinish: () => {
                loadingFaculty.value = false;
            },
        },
    );
};

const fullName = (faculty) => {
    const middleInitial = faculty.middle_name ? ` ${faculty.middle_name.charAt(0)}.` : '';
    const suffix = faculty.suffix ? ` ${faculty.suffix}` : '';
    return `${faculty.last_name}, ${faculty.first_name}${middleInitial}${suffix}`;
};

/* ------------------------------------------------------------------ */
/* Subject assignment (right panel)                                    */
/* ------------------------------------------------------------------ */

const selectedSubjectIds = ref([]);
const saving = ref(false);

// Reset the working selection whenever a different faculty is loaded.
watch(
    () => props.selectedFaculty?.id,
    () => {
        selectedSubjectIds.value = (props.selectedFaculty?.subjects ?? []).map((subject) => subject.id);
    },
    { immediate: true },
);

const isDirty = computed(() => {
    const current = new Set(selectedSubjectIds.value);
    const original = new Set((props.selectedFaculty?.subjects ?? []).map((subject) => subject.id));
    if (current.size !== original.size) return true;
    for (const id of current) {
        if (!original.has(id)) return true;
    }
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
    if (!props.selectedFaculty) return;

    saving.value = true;

    router.put(
        route('scheduling.teaching-qualifications.update', props.selectedFaculty.id),
        { subject_ids: selectedSubjectIds.value },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['selectedFaculty', 'flash'],
            onFinish: () => {
                saving.value = false;
            },
        },
    );
};
</script>

<template>
    <Head title="Teaching Qualifications" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Teaching Qualifications</span>
        </template>

        <div class="max-w-7xl mx-auto w-full">
            <!-- Page Title -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">Teaching Qualifications</h1>
                <p class="mt-1 text-slate-500">
                    Assign which subjects each faculty member is qualified to teach.
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[22rem_1fr] gap-6 items-start">
                <!-- LEFT PANEL: Faculty List -->
                <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                    <template #content>
                        <span class="relative w-full block mb-4">
                            <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                            <InputText
                                v-model="search"
                                placeholder="Search Faculty"
                                class="w-full !pl-9"
                            />
                        </span>

                        <div class="max-h-[32rem] overflow-y-auto -mx-2">
                            <button
                                v-for="faculty in faculties"
                                :key="faculty.id"
                                type="button"
                                class="w-full text-left px-3 py-2.5 rounded-lg mb-1 transition-colors"
                                :class="faculty.id === selectedFaculty?.id
                                    ? 'bg-[#2563EB]/10 border border-[#2563EB]/30'
                                    : 'border border-transparent hover:bg-slate-50'"
                                @click="selectFaculty(faculty)"
                            >
                                <p class="text-sm font-semibold text-[#1E293B]">{{ fullName(faculty) }}</p>
                                <p class="text-xs text-slate-500 mt-0.5">
                                    {{ faculty.faculty_id }} &middot; {{ faculty.department?.name || '—' }}
                                </p>
                                <p class="text-xs text-slate-400 mt-0.5">{{ faculty.employment_type }}</p>
                            </button>

                            <div v-if="!loadingList && faculties.length === 0" class="text-center py-10">
                                <p class="text-slate-500 font-medium text-sm">No faculty found.</p>
                            </div>
                        </div>
                    </template>
                </Card>

                <!-- RIGHT PANEL: Selected Faculty + Subject Assignment -->
                <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                    <template #content>
                        <div v-if="!selectedFaculty" class="text-center py-16">
                            <p class="text-slate-500 font-medium">Select a faculty member</p>
                            <p class="text-slate-400 text-sm mt-1">
                                Choose someone from the list on the left to manage their teaching qualifications.
                            </p>
                        </div>

                        <div v-else>
                            <!-- Selected Faculty Information -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pb-5 border-b border-slate-100">
                                <div>
                                    <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Faculty Name</p>
                                    <p class="text-sm font-semibold text-[#1E293B] mt-1">{{ fullName(selectedFaculty) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Department</p>
                                    <p class="text-sm font-semibold text-[#1E293B] mt-1">
                                        {{ selectedFaculty.department?.name || '—' }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">
                                        Maximum Teaching Units
                                    </p>
                                    <p class="text-sm font-semibold text-[#1E293B] mt-1">
                                        {{ selectedFaculty.max_teaching_units }}
                                    </p>
                                </div>
                                <div class="sm:col-span-3">
                                    <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">
                                        Current Assigned Subjects
                                    </p>
                                    <p class="text-sm font-semibold text-[#1E293B] mt-1">
                                        {{ selectedFaculty.subjects?.length ?? 0 }} subject(s)
                                    </p>
                                </div>
                            </div>

                            <!-- Subject Assignment -->
                            <div class="pt-5">
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
                                        :loading="saving"
                                        :disabled="!isDirty"
                                        @click="saveQualifications"
                                    />
                                </div>
                            </div>

                            <!-- Assigned Subjects Table -->
                            <DataTable
                                :value="assignedSubjects"
                                dataKey="id"
                                class="rounded-xl overflow-hidden mt-4"
                                stripedRows
                                responsiveLayout="scroll"
                            >
                                <template #empty>
                                    <div class="text-center py-8">
                                        <p class="text-slate-500 font-medium text-sm">No subjects assigned yet.</p>
                                        <p class="text-slate-400 text-xs mt-1">
                                            Use the selector above to assign subjects, then click "Save Qualifications".
                                        </p>
                                    </div>
                                </template>

                                <Column field="subject_code" header="Subject Code" style="width: 10rem" />
                                <Column field="subject_title" header="Subject Title" />
                                <Column header="Category" style="width: 11rem">
                                    <template #body="{ data }">
                                        <Tag :value="data.category" severity="info" />
                                    </template>
                                </Column>
                                <Column header="Units" style="width: 7rem">
                                    <template #body="{ data }">
                                        {{ data.units }}
                                    </template>
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

                            <p v-if="isDirty" class="text-xs text-amber-600 mt-2">
                                You have unsaved changes — click "Save Qualifications" to apply them.
                            </p>
                        </div>
                    </template>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>