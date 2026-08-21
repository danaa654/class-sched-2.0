<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
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
// Section dropdown narrows to whatever's currently selected above it
// (Academic Year, Semester, College/Program → Major, Year Level,
// Section Type) — mirrors majorOptions' self-filtering pattern just
// above. Without this, every Section across every term/college ever
// created shows here (including duplicate-looking codes like two
// different terms both having a "BSIT-4A"), which is confusing and
// lets someone pick a Section that doesn't even match their other
// filters. collegeToMajorIds resolves College/Program → Major ids
// since Section only stores major_id, not college_id directly.
const collegeToMajorIds = computed(() => {
    if (!form.value.college_id) return null;
    const college = props.filterOptions.colleges.find((c) => c.id === form.value.college_id);
    return college ? new Set(college.majors.map((m) => m.id)) : new Set();
});
const sectionOptions = computed(() => {
    const filtered = props.filterOptions.sections.filter((s) => {
        if (form.value.academic_year && s.academic_year !== form.value.academic_year) return false;
        if (form.value.semester && s.semester !== form.value.semester) return false;
        if (form.value.major_id && s.major_id !== form.value.major_id) return false;
        if (!form.value.major_id && collegeToMajorIds.value && !collegeToMajorIds.value.has(s.major_id)) return false;
        if (form.value.year_level && s.year_level !== form.value.year_level) return false;
        if (form.value.section_type && s.section_type !== form.value.section_type) return false;
        return true;
    });
    return [{ label: 'All Sections', value: '' }, ...filtered.map((s) => ({ label: s.section_code, value: s.id }))];
});

// If a previously selected Section no longer matches the current
// Academic Year/Semester/College/Major/Year Level/Section Type
// filters (e.g. the person picked a Section, then changed Semester),
// clear it rather than silently keep an invalid, now-hidden selection.
watch(sectionOptions, (options) => {
    if (form.value.section_id && ! options.some((o) => o.value === form.value.section_id)) {
        form.value.section_id = '';
    }
});
const sectionTypeOptions = [{ label: 'All Types', value: '' }, { label: 'Regular', value: 'Regular' }, { label: 'Irregular', value: 'Irregular' }];
const facultyOptions = computed(() => [{ label: 'All Faculty', value: '' }, ...props.filterOptions.faculty.map((f) => ({ label: f.name, value: f.id }))]);
const roomOptions = computed(() => [{ label: 'All Rooms', value: '' }, ...props.filterOptions.rooms.map((r) => ({ label: r.room_name, value: r.id }))]);

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
const needsFaculty = computed(() => ['schedule_by_faculty', 'faculty_teaching_load'].includes(reportType.value));
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
    // Opens the branded, server-rendered print view (see
    // ReportsController::print() / resources/views/reports/print.blade.php)
    // in its own tab, scoped with the exact same filters currently on
    // screen — rather than window.print()-ing the SPA page itself,
    // which used to print the app's own chrome/columns instead of a
    // proper school-letterhead document.
    const query = { ...form.value, term: selectedTerm.value, report_type: reportType.value };
    window.open(route('reports.print', query), '_blank');
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

// Dashboard Summary tiles — icon + accent per metric, same neu-icon-well
// pattern used on the Scheduling Dashboard's stat cards.
const summaryCards = computed(() => [
    { label: 'Programs', value: props.summary.total_programs, icon: 'pi-sitemap', color: isDark.value ? '#5B9CFF' : '#2563EB', glow: isDark.value ? 'rgba(91, 156, 255, 0.3)' : 'rgba(37, 99, 235, 0.25)' },
    { label: 'Sections', value: props.summary.total_sections, icon: 'pi-th-large', color: isDark.value ? '#5B9CFF' : '#2563EB', glow: isDark.value ? 'rgba(91, 156, 255, 0.3)' : 'rgba(37, 99, 235, 0.25)' },
    { label: 'Regular', value: props.summary.regular_sections, icon: 'pi-check-circle', color: isDark.value ? '#34D399' : '#059669', glow: isDark.value ? 'rgba(52, 211, 153, 0.3)' : 'rgba(5, 150, 105, 0.25)' },
    { label: 'Irregular', value: props.summary.irregular_sections, icon: 'pi-exclamation-circle', color: isDark.value ? '#FBBF24' : '#D97706', glow: isDark.value ? 'rgba(251, 191, 36, 0.3)' : 'rgba(217, 119, 6, 0.25)' },
    { label: 'Subjects', value: props.summary.total_subjects, icon: 'pi-book', color: isDark.value ? '#C4B5FD' : '#7C3AED', glow: isDark.value ? 'rgba(196, 181, 253, 0.3)' : 'rgba(124, 58, 237, 0.25)' },
    { label: 'Scheduled', value: props.summary.scheduled_subjects, icon: 'pi-calendar-plus', color: isDark.value ? '#34D399' : '#059669', glow: isDark.value ? 'rgba(52, 211, 153, 0.3)' : 'rgba(5, 150, 105, 0.25)' },
    { label: 'Unscheduled', value: props.summary.unscheduled_subjects, icon: 'pi-calendar-times', color: isDark.value ? '#FCA5A5' : '#DC2626', glow: isDark.value ? 'rgba(252, 165, 165, 0.3)' : 'rgba(220, 38, 38, 0.2)' },
    { label: 'Faculty', value: props.summary.total_faculty, icon: 'pi-users', color: isDark.value ? '#C4B5FD' : '#7C3AED', glow: isDark.value ? 'rgba(196, 181, 253, 0.3)' : 'rgba(124, 58, 237, 0.25)' },
    { label: 'Rooms', value: props.summary.total_rooms, icon: 'pi-building', color: isDark.value ? '#C4B5FD' : '#7C3AED', glow: isDark.value ? 'rgba(196, 181, 253, 0.3)' : 'rgba(124, 58, 237, 0.25)' },
    { label: 'Scheduling Completion', value: `${props.summary.completion_percent}%`, icon: 'pi-percentage', color: isDark.value ? '#34D399' : '#059669', glow: isDark.value ? 'rgba(52, 211, 153, 0.3)' : 'rgba(5, 150, 105, 0.25)' },
]);
</script>

<template>
    <Head title="Reports" />

    <AppLayout>
        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Reports</span>
        </template>

        <div class="max-w-7xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <div class="mb-8 flex items-center justify-between no-print">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
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
                    <p class="mt-1" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Generate and view academic scheduling reports.</p>
                </div>
            </div>

            <!-- Global Filters -->
            <div class="neu-card no-print rounded-2xl p-6 transition-colors duration-300">
                <div class="grid grid-cols-2 gap-4 md:grid-cols-4 neu-form">
                    <div>
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Term</label>
                        <Select
                            v-model="selectedTerm"
                            :options="termOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="neu-inset w-full mt-1 !rounded-xl !border-none"
                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                            @change="onTermChange"
                        >
                            <template #option="{ option }">
                                <span class="flex items-center gap-2">
                                    {{ option.label }}
                                    <Tag v-if="option.status === 'Archived'" value="Archived" severity="warn" class="!text-[10px] !py-0.5" />
                                </span>
                            </template>
                        </Select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Academic Year</label>
                        <Select v-model="form.academic_year" :options="academicYearOptions" optionLabel="label" optionValue="value" placeholder="All Years" showClear class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Semester</label>
                        <Select v-model="form.semester" :options="semesterOptions" optionLabel="label" optionValue="value" placeholder="All Semesters" showClear class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">College / Program</label>
                        <Select v-model="form.college_id" :options="collegeOptions" optionLabel="label" optionValue="value" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Major</label>
                        <Select v-model="form.major_id" :options="majorOptions" optionLabel="label" optionValue="value" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Year Level</label>
                        <Select v-model="form.year_level" :options="yearLevelOptions" optionLabel="label" optionValue="value" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Section Type</label>
                        <Select v-model="form.section_type" :disabled="forcesIrregular" :options="sectionTypeOptions" optionLabel="label" optionValue="value" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div v-if="needsSection">
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Section</label>
                        <Select v-model="form.section_id" :options="sectionOptions" optionLabel="label" optionValue="value" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div v-if="needsFaculty">
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Faculty</label>
                        <Select v-model="form.faculty_id" :options="facultyOptions" optionLabel="label" optionValue="value" filter class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div v-if="needsRoom">
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Room</label>
                        <Select v-model="form.room_id" :options="roomOptions" optionLabel="label" optionValue="value" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Report Type</label>
                        <Select v-model="reportType" :options="reportTypeOptions" optionLabel="label" optionValue="value" optionGroupLabel="label" optionGroupChildren="items" placeholder="Select Report" class="neu-inset w-full mt-1 !rounded-xl !border-none" :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }" />
                    </div>
                </div>
                <div class="mt-5 flex gap-2">
                    <Button label="Generate Report" icon="pi pi-search" severity="success" :disabled="!reportType" @click="generate" />
                    <Button label="Reset Filters" severity="secondary" outlined @click="resetFilters" />
                </div>
            </div>

            <!-- Dashboard Summary -->
            <div class="mt-6 grid grid-cols-2 gap-4 no-print md:grid-cols-5">
                <div v-for="card in summaryCards" :key="card.label" class="neu-card rounded-2xl p-4 transition-colors duration-300">
                    <span
                        class="neu-icon-well neu-glow flex h-10 w-10 items-center justify-center rounded-xl"
                        :style="{ '--neu-glow-color': card.glow }"
                    >
                        <i class="pi text-base" :class="[card.icon]" :style="{ color: card.color }"></i>
                    </span>
                    <p class="mt-3 text-xl font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ card.value }}</p>
                    <p class="mt-1 text-xs font-semibold uppercase tracking-wide" :class="isDark ? 'text-slate-400' : 'text-slate-400'">{{ card.label }}</p>
                </div>
            </div>

            <!-- Report Results -->
            <div v-if="report" class="neu-card mt-6 rounded-2xl p-6 transition-colors duration-300">
                <div class="print-header hidden print:block mb-4">
                    <p class="text-lg font-bold">CLASSLY</p>
                    <p class="text-base font-semibold">{{ report.title }}</p>
                    <p class="text-xs text-slate-500" v-if="form.academic_year">Academic Year: {{ form.academic_year }}</p>
                    <p class="text-xs text-slate-500" v-if="form.semester">Semester: {{ form.semester }}</p>
                    <p class="text-xs text-slate-500">Generated: {{ generatedAt }}</p>
                </div>

                <div class="flex items-center justify-between no-print">
                    <div>
                        <h2 class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ report.title }}</h2>
                        <p class="text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'" v-if="activeFiltersLabel">{{ activeFiltersLabel }}</p>
                    </div>
                    <div class="flex gap-2">
                        <Button label="Print" icon="pi pi-print" severity="secondary" outlined class="report-btn report-btn--print" @click="printReport" />
                        <Button label="Export Excel" icon="pi pi-file-excel" severity="secondary" outlined class="report-btn report-btn--export" @click="exportCsv" :disabled="!report.rows.length" />
                    </div>
                </div>

                <div v-if="report.summary" class="mt-4 grid grid-cols-2 gap-3 no-print md:grid-cols-4">
                    <div v-for="(value, label) in report.summary" :key="label" class="neu-inset rounded-xl p-3 text-center">
                        <p class="text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ label }}</p>
                        <p class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ value }}</p>
                    </div>
                </div>

                <p v-if="!report.rows.length" class="py-10 text-center italic" :class="isDark ? 'text-slate-500' : 'text-slate-400'">{{ report.empty_message }}</p>

                <DataTable
                    v-else
                    :value="report.rows"
                    class="neu-inset neu-table mt-4 rounded-xl overflow-hidden"
                    :class="isDark ? 'neu-table-dark' : ''"
                    stripedRows
                    scrollable
                    paginator
                    :rows="15"
                >
                    <Column v-for="col in report.columns" :key="col" :field="col" :header="col">
                        <template #body="{ data }" v-if="isStatusColumn(col)">
                            <Tag :value="data[col]" :severity="statusSeverity(data[col])" v-if="data[col]" />
                            <span v-else>—</span>
                        </template>
                    </Column>
                </DataTable>
            </div>

            <div v-else class="neu-card mt-6 rounded-2xl p-6 transition-colors duration-300">
                <p class="py-10 text-center italic" :class="isDark ? 'text-slate-500' : 'text-slate-400'">Select filters and a Report Type above, then click "Generate Report".</p>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Dark-mode overrides for teleported PrimeVue overlays (Select
   dropdown panels render outside this component via <Teleport>, so
   they need :global() rather than :deep()). Neumorphic surfaces
   (neu-card / neu-inset) already recolor automatically via the global
   ".dark" class — only the overlay panel and DataTable chrome need
   explicit handling here. */
.dark-scope :deep(.p-select-label) { color: #F8FAFC !important; }
.dark-scope :deep(.p-select-label.p-placeholder) { color: #7C8CA8 !important; }
.dark-scope :deep(.p-select.p-disabled) { background: rgba(255, 255, 255, 0.03) !important; color: #64748B !important; }

.dark-scope :deep(.p-button-outlined.p-button-secondary) { color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.2) !important; background: transparent !important; }
.dark-scope :deep(.p-button-outlined.p-button-secondary:hover) { background: rgba(255, 255, 255, 0.08) !important; }

.dark-scope :deep(.p-datatable) { background: transparent !important; color: #F1F5F9 !important; }
.dark-scope :deep(.p-datatable-thead > tr > th) { background: #1C2947 !important; color: #F8FAFC !important; border-color: rgba(255, 255, 255, 0.14) !important; font-weight: 700 !important; }
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

:global(.p-select-overlay.dark-scope) { background: #0F1730 !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #F8FAFC !important; }
:global(.p-select-overlay.dark-scope .p-select-option) { color: #F1F5F9 !important; }
:global(.p-select-overlay.dark-scope .p-select-option:hover) { background: rgba(255, 255, 255, 0.08) !important; }
:global(.p-select-overlay.dark-scope .p-select-option-group) { background: rgba(255, 255, 255, 0.04) !important; color: #94A3B8 !important; }
:global(.p-select-overlay.dark-scope .p-select-filter) { background: rgba(255, 255, 255, 0.06) !important; color: #F8FAFC !important; border-color: rgba(255, 255, 255, 0.15) !important; }
:global(.p-select-overlay.dark-scope .p-select-empty-message) { color: #94A3B8 !important; }

/* Print / Export Excel: on hover, tint the outline + text to a
   semantic color (green = print, blue = export) with a matching soft
   glow, instead of the generic gray outline hover. */
.report-btn--print:hover:not(:disabled) {
    border-color: rgba(34, 197, 94, 0.6) !important;
    color: #16A34A !important;
    background: rgba(34, 197, 94, 0.08) !important;
    box-shadow: 0 0 12px 1px rgba(34, 197, 94, 0.35) !important;
}
.report-btn--export:hover:not(:disabled) {
    border-color: rgba(37, 99, 235, 0.6) !important;
    color: #2563EB !important;
    background: rgba(37, 99, 235, 0.08) !important;
    box-shadow: 0 0 12px 1px rgba(37, 99, 235, 0.35) !important;
}

.dark-scope :deep(.report-btn--print:hover:not(:disabled)) {
    color: #4ADE80 !important;
    box-shadow: 0 0 16px 2px rgba(34, 197, 94, 0.5) !important;
}
.dark-scope :deep(.report-btn--export:hover:not(:disabled)) {
    color: #60A5FA !important;
    box-shadow: 0 0 16px 2px rgba(37, 99, 235, 0.5) !important;
}

/* DataTable's horizontal scrollbar was rendering as the browser's
   chunky default (bright blue on some platforms) — thin it out and
   theme it to match the rest of the app's slim scrollbars. */
.dark-scope :deep(.p-datatable-table-container) {
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 255, 255, 0.2) transparent;
}
.dark-scope :deep(.p-datatable-table-container::-webkit-scrollbar) {
    height: 6px;
    width: 6px;
}
.dark-scope :deep(.p-datatable-table-container::-webkit-scrollbar-thumb) {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 999px;
}
.dark-scope :deep(.p-datatable-table-container::-webkit-scrollbar-track) {
    background: transparent;
}
:deep(.p-datatable-table-container) {
    scrollbar-width: thin;
    scrollbar-color: rgba(148, 163, 184, 0.5) transparent;
}
:deep(.p-datatable-table-container::-webkit-scrollbar) {
    height: 6px;
    width: 6px;
}
:deep(.p-datatable-table-container::-webkit-scrollbar-thumb) {
    background: rgba(148, 163, 184, 0.5);
    border-radius: 999px;
}
:deep(.p-datatable-table-container::-webkit-scrollbar-track) {
    background: transparent;
}

/* Light-mode header row: PrimeVue's default header background is a
   near-white tint that barely contrasts against the neu-card surface
   it sits on — give it a solid, clearly darker background instead. */
:deep(.p-datatable-thead > tr > th) {
    background: #EEF1F6 !important;
    color: #1E293B !important;
    font-weight: 700 !important;
}
</style>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    header, aside, nav, .no-print {
        display: none !important;
    }
    .neu-card, .neu-card * {
        visibility: visible;
    }
    .neu-card {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        box-shadow: none !important;
        border: none !important;
    }
}
</style>