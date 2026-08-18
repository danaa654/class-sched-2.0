<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Select from 'primevue/select';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    filterOptions: { type: Object, required: true },
    filters: { type: Object, required: true },
    termOptions: { type: Array, default: () => [] },
    reportType: { type: String, default: '' },
    summary: { type: Object, required: true },
    report: { type: Object, default: null },
    generatedAt: { type: String, default: '' },
});

// --- Local filter state (mirrors querystring) ---
const form = ref({
    academic_year: props.filters.academic_year || '',
    semester: props.filters.semester || '',
    college_id: props.filters.college_id || '',
    major_id: props.filters.major_id || '',
    year_level: props.filters.year_level || '',
    section_id: props.filters.section_id || '',
    section_type: props.filters.section_type || '',
    faculty_id: props.filters.faculty_id || '',
    room_id: props.filters.room_id || '',
});
const reportType = ref(props.reportType || '');

// Academic Term quick filter — same "{academic_year}|{semester}" value
// shape and "All Terms" / Archived-tagged options as the Sections page
// (see SectionController@termFilterOptions). Choosing a term just fills
// in the existing Academic Year + Semester selects below it; it doesn't
// bypass them, so either can still be fine-tuned independently before
// clicking Generate Report.
const selectedTerm = ref(props.filters.term || 'all');

function onTermChange() {
    if (selectedTerm.value === 'all') {
        form.value.academic_year = '';
        form.value.semester = '';
        return;
    }

    const [termYear, termSemester] = selectedTerm.value.split('|');
    form.value.academic_year = termYear ?? '';
    form.value.semester = termSemester ?? '';
}

const academicYearOptions = computed(() => props.filterOptions.academicYears.map((v) => ({ label: v, value: v })));
const semesterOptions = computed(() => props.filterOptions.semesters.map((v) => ({ label: v, value: v })));
const collegeOptions = computed(() => [{ label: 'All Programs', value: '' }, ...props.filterOptions.colleges.map((c) => ({ label: c.name, value: c.id }))]);
const majorOptions = computed(() => {
    const college = props.filterOptions.colleges.find((c) => c.id === form.value.college_id);
    const majors = college ? college.majors : props.filterOptions.colleges.flatMap((c) => c.majors);
    return [{ label: 'All Majors', value: '' }, ...majors.map((m) => ({ label: m.name, value: m.id }))];
});
const yearLevelOptions = computed(() => [{ label: 'All Year Levels', value: '' }, ...props.filterOptions.yearLevels.map((v) => ({ label: v, value: v }))]);
const sectionOptions = computed(() => [{ label: 'All Sections', value: '' }, ...props.filterOptions.sections.map((s) => ({ label: s.section_code, value: s.id }))]);
const sectionTypeOptions = [{ label: 'All Types', value: '' }, { label: 'Regular', value: 'Regular' }, { label: 'Irregular', value: 'Irregular' }];
const facultyOptions = computed(() => [{ label: 'All Faculty', value: '' }, ...props.filterOptions.faculty.map((f) => ({ label: f.name, value: f.id }))]);
const roomOptions = computed(() => [{ label: 'All Rooms', value: '' }, ...props.filterOptions.rooms.map((r) => ({ label: r.room_code, value: r.id }))]);

const reportTypeOptions = computed(() => {
    const groups = [];
    for (const [group, entries] of Object.entries(props.filterOptions.reportGroups)) {
        groups.push({
            label: group,
            items: Object.entries(entries).map(([value, label]) => ({ label, value })),
        });
    }
    return groups;
});

// Which selectors are relevant to the currently chosen report
const needsFaculty = computed(() => ['schedule_by_faculty', 'faculty_teaching_load', 'faculty_availability'].includes(reportType.value));
const needsRoom = computed(() => ['schedule_by_room', 'room_utilization', 'room_conflicts'].includes(reportType.value));
const needsSection = computed(() => ['schedule_by_section', 'section_subjects'].includes(reportType.value));
const forcesIrregular = computed(() => ['irregular_sections', 'irregular_merge_report'].includes(reportType.value));

function generate() {
    router.get(route('reports'), { ...form.value, term: selectedTerm.value, report_type: reportType.value }, { preserveState: true, preserveScroll: true });
}

function resetFilters() {
    form.value = { academic_year: '', semester: '', college_id: '', major_id: '', year_level: '', section_id: '', section_type: '', faculty_id: '', room_id: '' };
    selectedTerm.value = 'all';
    reportType.value = '';
    // Explicitly send term=all (not an empty query) so this always
    // clears back to "All Years" — an empty query object would look
    // identical to a first-ever visit and re-default to the Active
    // Academic Term instead of actually resetting.
    router.get(route('reports'), { term: 'all', academic_year: '', semester: '' }, { preserveState: false });
}

function printReport() {
    window.print();
}

function exportCsv() {
    if (!props.report || !props.report.rows.length) return;
    const columns = props.report.columns;
    const escape = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const lines = [columns.map(escape).join(',')];
    for (const row of props.report.rows) {
        lines.push(columns.map((c) => escape(row[c])).join(','));
    }
    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${(props.report.title || 'report').toLowerCase().replace(/[^a-z0-9]+/g, '-')}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

function statusSeverity(value) {
    const map = {
        Scheduled: 'success',
        Merged: 'success',
        'Partially Scheduled': 'warn',
        Unscheduled: 'danger',
        Conflict: 'danger',
        Independent: 'info',
        Draft: 'secondary',
    };
    return map[value] || null;
}

const isStatusColumn = (col) => /status$/i.test(col) || col === 'Merge Status' || col === 'Availability Status';

const activeFiltersLabel = computed(() => {
    const parts = [];
    if (form.value.academic_year) parts.push(form.value.academic_year);
    if (form.value.semester) parts.push(form.value.semester);
    if (form.value.section_type) parts.push(form.value.section_type);
    return parts.join(' · ');
});
</script>

<template>
    <Head title="Reports" />

    <AppLayout>
        <div :class="isDark ? 'dark-scope' : ''">
        <div class="flex items-center justify-between no-print">
            <div>
                <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2 text-[#1E293B]">
                    Reports
                    <InfoPopover
                        title="Reports"
                        :paragraphs="[
                            'Generate read-only reports on scheduling, faculty load, room usage, and sections for a given academic term.',
                        ]"
                        :bullets="[
                            'Choose a Term (or Academic Year + Semester) and a Report Type, then click \'Generate Report\'.',
                            'Some report types reveal extra filters — e.g. Faculty or Room — once selected.',
                            'Use Print or Export Excel to save a generated report; reports themselves are not saved in the system.',
                        ]"
                    />
                </h1>
                <p class="mt-1 text-slate-500">Generate and view academic scheduling reports.</p>
            </div>
        </div>

        <!-- Global Filters -->
        <Card class="mt-6 no-print">
            <template #content>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <label class="text-xs font-semibold text-slate-500">Term</label>
                        <Select v-model="selectedTerm" :options="termOptions" optionLabel="label" optionValue="value" class="w-full mt-1" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" @change="onTermChange">
                            <template #option="{ option }">
                                <span class="flex items-center gap-2">
                                    {{ option.label }}
                                    <Tag v-if="option.status === 'Archived'" value="Archived" severity="warn" class="!text-[10px] !py-0.5" />
                                </span>
                            </template>
                        </Select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-500">Academic Year</label>
                        <Select v-model="form.academic_year" :options="academicYearOptions" optionLabel="label" optionValue="value" placeholder="All Years" showClear class="w-full mt-1" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-500">Semester</label>
                        <Select v-model="form.semester" :options="semesterOptions" optionLabel="label" optionValue="value" placeholder="All Semesters" showClear class="w-full mt-1" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-500">College / Program</label>
                        <Select v-model="form.college_id" :options="collegeOptions" optionLabel="label" optionValue="value" class="w-full mt-1" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-500">Major</label>
                        <Select v-model="form.major_id" :options="majorOptions" optionLabel="label" optionValue="value" class="w-full mt-1" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-500">Year Level</label>
                        <Select v-model="form.year_level" :options="yearLevelOptions" optionLabel="label" optionValue="value" class="w-full mt-1" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-500">Section Type</label>
                        <Select v-model="form.section_type" :disabled="forcesIrregular" :options="sectionTypeOptions" optionLabel="label" optionValue="value" class="w-full mt-1" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div v-if="needsSection">
                        <label class="text-xs font-semibold text-slate-500">Section</label>
                        <Select v-model="form.section_id" :options="sectionOptions" optionLabel="label" optionValue="value" class="w-full mt-1" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div v-if="needsFaculty">
                        <label class="text-xs font-semibold text-slate-500">Faculty</label>
                        <Select v-model="form.faculty_id" :options="facultyOptions" optionLabel="label" optionValue="value" filter class="w-full mt-1" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div v-if="needsRoom">
                        <label class="text-xs font-semibold text-slate-500">Room</label>
                        <Select v-model="form.room_id" :options="roomOptions" optionLabel="label" optionValue="value" class="w-full mt-1" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs font-semibold text-slate-500">Report Type</label>
                        <Select v-model="reportType" :options="reportTypeOptions" optionLabel="label" optionValue="value" optionGroupLabel="label" optionGroupChildren="items" placeholder="Select Report" class="w-full mt-1" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                </div>
                <div class="flex gap-2 mt-4">
                    <Button label="Generate Report" icon="pi pi-search" :disabled="!reportType" @click="generate" />
                    <Button label="Reset Filters" severity="secondary" outlined @click="resetFilters" />
                </div>
            </template>
        </Card>

        <!-- Dashboard Summary -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-6 no-print">
            <Card v-for="card in [
                ['Programs', summary.total_programs],
                ['Sections', summary.total_sections],
                ['Regular', summary.regular_sections],
                ['Irregular', summary.irregular_sections],
                ['Subjects', summary.total_subjects],
                ['Scheduled', summary.scheduled_subjects],
                ['Unscheduled', summary.unscheduled_subjects],
                ['Faculty', summary.total_faculty],
                ['Rooms', summary.total_rooms],
            ]" :key="card[0]">
                <template #content>
                    <p class="text-xs text-slate-500">{{ card[0] }}</p>
                    <p class="text-xl font-bold text-slate-800">{{ card[1] }}</p>
                </template>
            </Card>
            <Card>
                <template #content>
                    <p class="text-xs text-slate-500">Scheduling Completion</p>
                    <p class="text-xl font-bold text-emerald-600">{{ summary.completion_percent }}%</p>
                </template>
            </Card>
        </div>

        <!-- Report Results -->
        <Card class="mt-6" v-if="report">
            <template #content>
                <div class="print-header hidden print:block mb-4">
                    <p class="text-lg font-bold">CLASSLY</p>
                    <p class="text-base font-semibold">{{ report.title }}</p>
                    <p class="text-xs text-slate-500" v-if="form.academic_year">Academic Year: {{ form.academic_year }}</p>
                    <p class="text-xs text-slate-500" v-if="form.semester">Semester: {{ form.semester }}</p>
                    <p class="text-xs text-slate-500">Generated: {{ generatedAt }}</p>
                </div>

                <div class="flex items-center justify-between no-print">
                    <div>
                        <h2 class="text-lg font-bold text-slate-800">{{ report.title }}</h2>
                        <p class="text-xs text-slate-500" v-if="activeFiltersLabel">{{ activeFiltersLabel }}</p>
                    </div>
                    <div class="flex gap-2">
                        <Button label="Print" icon="pi pi-print" severity="secondary" outlined @click="printReport" />
                        <Button label="Export Excel" icon="pi pi-file-excel" severity="secondary" outlined @click="exportCsv" :disabled="!report.rows.length" />
                    </div>
                </div>

                <div v-if="report.summary" class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4 no-print">
                    <div v-for="(value, label) in report.summary" :key="label" class="rounded-lg border border-slate-100 p-3 text-center">
                        <p class="text-xs text-slate-500">{{ label }}</p>
                        <p class="text-lg font-bold text-slate-800">{{ value }}</p>
                    </div>
                </div>

                <p v-if="!report.rows.length" class="text-center text-slate-400 italic py-10">{{ report.empty_message }}</p>

                <DataTable v-else :value="report.rows" class="mt-4" stripedRows scrollable paginator :rows="15">
                    <Column v-for="col in report.columns" :key="col" :field="col" :header="col">
                        <template #body="{ data }" v-if="isStatusColumn(col)">
                            <Tag :value="data[col]" :severity="statusSeverity(data[col])" v-if="data[col]" />
                            <span v-else>—</span>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <Card class="mt-6" v-else>
            <template #content>
                <p class="text-center text-slate-400 italic py-10">Select filters and a Report Type above, then click "Generate Report".</p>
            </template>
        </Card>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Dark-mode overrides — same "dark-scope" pattern used across the rest
   of the app. Wrapping the page body lets these rules recolor
   PrimeVue Cards/DataTable/Select and plain Tailwind utility classes
   only when the app theme is dark. */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-800) { color: #F1F5F9 !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-card) { background: #101A35 !important; color: #F8FAFC; border: 1px solid rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-select) {
    background: rgba(255, 255, 255, 0.05) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-select-label) { color: #F8FAFC !important; }
.dark-scope :deep(.p-select-label.p-placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-select.p-disabled) { background: rgba(255, 255, 255, 0.03) !important; color: #64748B !important; }

.dark-scope :deep(.p-button-outlined.p-button-secondary) { color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.2) !important; background: transparent !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; }

.dark-scope :deep(.p-datatable) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.06) !important; color: #F1F5F9 !important; border-color: rgba(255, 255, 255, 0.12) !important; }
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

/* Report summary tiles + row-level "rounded-lg border" blocks */
.dark-scope :deep(.rounded-lg.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; background: rgba(255, 255, 255, 0.04) !important; }

:global(.p-select-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-select-overlay.dark-scope .p-select-option) { color: #F1F5F9 !important; }
:global(.p-select-overlay.dark-scope .p-select-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
:global(.p-select-overlay.dark-scope .p-select-option-group) { background: rgba(255, 255, 255, 0.04) !important; color: #94A3B8 !important; }
:global(.p-select-overlay.dark-scope .p-select-filter) { background: rgba(255, 255, 255, 0.06) !important; color: #F8FAFC !important; border-color: rgba(255, 255, 255, 0.15) !important; }
:global(.p-select-overlay.dark-scope .p-select-empty-message) { color: #94A3B8 !important; }
</style>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    header, aside, nav, .no-print {
        display: none !important;
    }
    .p-card, .p-card * {
        visibility: visible;
    }
    .p-card {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        box-shadow: none !important;
        border: none !important;
    }
}
</style>