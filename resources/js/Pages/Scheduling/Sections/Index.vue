<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
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
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

// PrimeVue's Dialog is teleported straight to <body>, so it sits
// outside this page's normal DOM tree — the "dark-scope" class still
// reaches it via :deep() (see the <style> block below) for text/input
// colors, but the Dialog's own chrome (panel/header/content/footer
// background) is safest set as literal inline styles here rather than
// relying purely on CSS specificity to beat PrimeVue's theme, since
// an inline style is guaranteed to win regardless of stylesheet order.
const darkDialogPt = computed(() => {
    if (!isDark.value) return { root: { class: '' } };

    const panel = { background: '#0F1730', color: '#F8FAFC', border: '1px solid rgba(255,255,255,0.1)' };

    return {
        root: { class: 'dark-scope', style: panel },
        header: { style: { background: '#0F1730', color: '#F8FAFC', borderBottom: '1px solid rgba(255,255,255,0.1)' } },
        content: { style: { background: '#0F1730', color: '#F8FAFC' } },
        footer: { style: { background: '#0F1730', borderTop: '1px solid rgba(255,255,255,0.1)' } },
    };
});

const props = defineProps({
    sections: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    filters: {
        type: Object,
        default: () => ({ section_search: '' }),
    },
    activeMajors: { type: Array, default: () => [] },
    curriculums: { type: Array, default: () => [] },
    yearLevels: { type: Array, default: () => [] },
    academicTermOptions: { type: Array, default: () => [] },
    termOptions: { type: Array, default: () => [] },
    sectionTypes: { type: Array, default: () => ['Regular', 'Irregular'] },
});

const toast = useToast();
const page = usePage();

// Restricted-role (Dean/OIC) College lock indicator for the Add
// Section / Batch Add forms. The `activeMajors` list sent from the
// server is ALREADY filtered to this user's authorized College(s) —
// this is purely a UI hint, never the authorization boundary itself;
// SectionController re-derives and re-checks the College server-side
// on every create request regardless of what's shown here.
const scopedCollegeId = computed(() => page.props.auth?.collegeId ?? null);
// SECTION-LEVEL SCHEDULE FINALIZATION — Registrar/Admin only, both to
// finalize AND to unlock (SectionPolicy::finalize()/unlockSchedule()).
// This is UI-visibility only; the Policy re-checks server-side.
const canManageFinalization = computed(() => !!page.props.auth?.can?.manageFinalization);
const hasNoAssignedCollege = computed(() => !!page.props.auth?.hasNoAssignedCollege);

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

const search = ref(props.filters.section_search ?? '');
const selectedTerm = ref(props.filters.term ?? 'all');
const loading = ref(false);
let searchDebounce = null;

const reloadSections = (extra = {}) => {
    loading.value = true;

    router.get(
        route('scheduling.sections'),
        { section_search: search.value, term: selectedTerm.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['sections'],
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

// Switching the Academic Term filter re-queries immediately (unlike
// the text search, which debounces) — it's a deliberate dropdown pick,
// not something the user is still typing.
const onTermChange = () => {
    reloadSections();
};

/**
 * Clicking a Section row jumps straight into "Manage Subjects" for that
 * Section — merges the old two-step "find it in Sections, then find it
 * again in Section Subjects" flow into one click. Edit/Delete buttons
 * in the Actions column call @click.stop so they don't also trigger
 * this navigation.
 */
const goToSectionSubjects = (section) => {
    router.get(route('scheduling.section-subjects.show', section.id));
};

const onRowClick = (event) => {
    goToSectionSubjects(event.data);
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        reloadSections({ section_page: 1 });
    }, 350);
});

const onPage = (event) => {
    reloadSections({ section_page: event.page + 1 });
};

const onRefresh = () => {
    reloadSections({ section_page: props.sections.current_page });
};

/* ------------------------------------------------------------------ */
/* Add / Edit Section                                                  */
/* ------------------------------------------------------------------ */

const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

const yearLevelOptions = computed(() => props.yearLevels.map((level) => ({ label: level, value: level })));
// Academic Year / Semester — sourced from real AcademicTerm records
// (see SectionController::academicTermSectionOptions()), not a
// generated range, so a Section can never be created for a School
// Year/Semester with no AcademicTerm behind it (no Scheduling
// Preferences, no class hours/days configured). Archived terms are
// already excluded server-side.
//
// Mirrors the Curriculum/Major and Rooms Department/College pattern
// already used in this app: the backend sends one flat list (each row
// carrying its own academic_year), and Semester options are filtered
// down to whichever Academic Year is currently selected on each form.
const academicYearOptions = computed(() => {
    const seen = new Set();
    const options = [];

    for (const term of props.academicTermOptions) {
        if (!seen.has(term.academic_year)) {
            seen.add(term.academic_year);
            options.push({ label: term.academic_year, value: term.academic_year });
        }
    }

    return options;
});

const semesterOptionsFor = (academicYear) => {
    if (!academicYear) {
        return [];
    }

    return props.academicTermOptions
        .filter((term) => term.academic_year === academicYear)
        .map((term) => ({ label: term.semester, value: term.semester }));
};

// A Section already saved under a term that's since been Archived (or,
// in principle, any academic_year/semester no longer in the list)
// would otherwise show a blank Select on its own Edit form — this adds
// its current value back in as a selectable option ONLY on that form,
// so opening Edit never silently clears a value the Section actually
// has. It intentionally doesn't affect the Add Section batch generator,
// which should only ever offer real, non-Archived terms.
const withCurrentValueOption = (options, academicYear, semester, valueKey) => {
    if (!academicYear || !semester) {
        return options;
    }

    const currentValue = valueKey === 'academic_year' ? academicYear : semester;
    const alreadyListed = options.some((option) => option.value === currentValue);

    return alreadyListed ? options : [...options, { label: currentValue, value: currentValue }];
};

const editAcademicYearOptions = computed(() =>
    withCurrentValueOption(academicYearOptions.value, sectionForm.academic_year, sectionForm.semester, 'academic_year'),
);
const editSemesterOptions = computed(() =>
    withCurrentValueOption(semesterOptionsFor(sectionForm.academic_year), sectionForm.academic_year, sectionForm.semester, 'semester'),
);
const batchSemesterOptions = computed(() => semesterOptionsFor(batchForm.academic_year));
const sectionTypeOptions = computed(() =>
    (props.sectionTypes ?? ['Regular', 'Irregular']).map((type) => ({ label: type, value: type })),
);

const addSectionVisible = ref(false);
const editSectionVisible = ref(false);
const editingSection = ref(null);

/* ------------------------------------------------------------------ */
/* Edit Section (single section — Section Code/Name stay editable      */
/* here so a generated name can still be fixed later)                  */
/* ------------------------------------------------------------------ */

const sectionForm = useForm({
    section_code: '',
    section_name: '',
    section_type: 'Regular',
    major_id: null,
    curriculum_id: null,
    year_level: null,
    academic_year: null,
    semester: null,
    estimated_students: 1,
    status: 'Active',
    remarks: '',
});

// Only show curriculums (Prospectuses) that belong to the selected
// College / Program.
const filteredCurriculums = computed(() => {
    if (!sectionForm.major_id) {
        return [];
    }

    return props.curriculums
        .filter((curriculum) => curriculum.major_id === sectionForm.major_id)
        .map((curriculum) => ({ label: `${curriculum.code} — ${curriculum.name}`, value: curriculum.id }));
});

// If the Program changes and the currently selected Prospectus no
// longer belongs to it, clear the Prospectus selection.
watch(
    () => sectionForm.major_id,
    () => {
        const stillValid = filteredCurriculums.value.some(
            (curriculum) => curriculum.value === sectionForm.curriculum_id,
        );
        if (!stillValid) {
            sectionForm.curriculum_id = null;
        }
    },
);

// If the Academic Year changes and the currently selected Semester
// isn't offered under it, clear the Semester selection — mirrors the
// Program -> Prospectus reset above.
watch(
    () => sectionForm.academic_year,
    () => {
        const stillValid = editSemesterOptions.value.some(
            (semester) => semester.value === sectionForm.semester,
        );
        if (!stillValid) {
            sectionForm.semester = null;
        }
    },
);


// Edit opens a dialog pre-filled with the section's current info, so a
// typo (section code, name, year level, etc.) can be fixed right here
// without leaving the list. Assigning subjects/faculty/rooms still
// only happens on the Section Subjects workspace.
const openEdit = (section) => {
    editingSection.value = section;
    sectionForm.clearErrors();
    sectionForm.section_code = section.section_code;
    sectionForm.section_name = section.section_name;
    sectionForm.section_type = section.section_type ?? 'Regular';
    sectionForm.major_id = section.major_id;
    sectionForm.curriculum_id = section.curriculum_id;
    sectionForm.year_level = section.year_level;
    sectionForm.academic_year = section.academic_year;
    sectionForm.semester = section.semester;
    sectionForm.estimated_students = section.estimated_students;
    sectionForm.status = section.status;
    sectionForm.remarks = section.remarks;
    editSectionVisible.value = true;
};

const closeEditSection = () => {
    editSectionVisible.value = false;
    editingSection.value = null;
    sectionForm.reset();
    sectionForm.clearErrors();
};

const onSaveSection = () => {
    // Guard against double-submit (double-click, or Enter + click) firing
    // two requests before the first one lands — both would pass the
    // "unique" validation check and the second insert would then crash
    // on the database's unique constraint instead of failing validation.
    if (sectionForm.processing || !editingSection.value) {
        return;
    }

    sectionForm.put(route('scheduling.sections.update', editingSection.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeEditSection();
            Swal.fire({
                title: 'Section updated',
                text: 'The section was updated successfully.',
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

/* ------------------------------------------------------------------ */
/* Add Section — batch generation flow                                 */
/* (Academic Year / Semester / Program / Year Level / Prospectus +     */
/* Section Prefix + Number of Blocks → BSIT-1A, BSIT-1B, ...)          */
/* ------------------------------------------------------------------ */

const batchForm = useForm({
    academic_year: null,
    semester: null,
    section_type: 'Regular',
    major_id: null,
    year_level: null,
    curriculum_id: null,
    section_prefix: '',
    number_of_blocks: 1,
    estimated_students_per_block: 35,
    status: 'Active',
    remarks: '',
});

// Prospectuses (Curriculum Items) for the selected College / Program.
const filteredProspectuses = computed(() => {
    if (!batchForm.major_id) {
        return [];
    }

    return props.curriculums
        .filter((curriculum) => curriculum.major_id === batchForm.major_id)
        .map((curriculum) => ({ label: `${curriculum.code} — ${curriculum.name}`, value: curriculum.id }));
});

watch(
    () => batchForm.major_id,
    () => {
        const stillValid = filteredProspectuses.value.some(
            (curriculum) => curriculum.value === batchForm.curriculum_id,
        );
        if (!stillValid) {
            batchForm.curriculum_id = null;
        }
    },
);

// Irregular sections don't necessarily follow one Prospectus — its
// subjects are picked manually later (Manual Selection), so Prospectus
// is optional/reference-only for them. Clear it when switching to
// Irregular so a stale selection doesn't linger unseen; the field
// stays visible (disabled+optional) rather than disappearing, since
// the admin may still want to reference one.
//
// Irregular sections are also a single scheduling group, not a set of
// A/B/C blocks (spec section 3) — Number of Blocks doesn't apply, and
// "Estimated Students per Block" becomes a single "Estimated Students"
// count defaulting to 5. Switching back to Regular restores its own
// defaults so re-toggling the type doesn't leave stale values behind.
watch(
    () => batchForm.section_type,
    (type) => {
        if (type === 'Irregular') {
            batchForm.curriculum_id = null;
            batchForm.number_of_blocks = 1;
            batchForm.estimated_students_per_block = 5;
        } else {
            batchForm.estimated_students_per_block = 35;
        }
    },
);

/**
 * Section Prefix auto-suggestion (College/Program + Year Level →
 * "BSIT-1", "BSIT-2", ...). Only overwrites the prefix while the admin
 * hasn't typed their own — tracked via `prefixManuallyEdited` so a
 * Program/Year Level change never silently clobbers a custom prefix.
 */
const prefixManuallyEdited = ref(false);

const yearLevelOrdinal = (yearLevel) => {
    const index = props.yearLevels.indexOf(yearLevel);
    return index === -1 ? null : index + 1;
};

const suggestedPrefix = computed(() => {
    const major = props.activeMajors.find((m) => m.id === batchForm.major_id);
    const ordinal = yearLevelOrdinal(batchForm.year_level);

    if (!major || !ordinal) {
        return '';
    }

    const base = `${major.code}-${ordinal}`;

    // Irregular sections are a single named group (e.g. "BSIT-4-IRREG"),
    // not a letter-suffixed block — see nextIrregularName()'s docblock
    // on the backend. The base "BSIT-4" suggestion still applies, just
    // with the "-IRREG" marker appended.
    return batchForm.section_type === 'Irregular' ? `${base}-IRREG` : base;
});

watch(suggestedPrefix, (suggestion) => {
    if (suggestion && !prefixManuallyEdited.value) {
        batchForm.section_prefix = suggestion;
    }
});

const onPrefixInput = () => {
    // Once the admin types something that no longer matches the
    // auto-suggestion, stop overwriting it on further Program/Year
    // Level changes.
    prefixManuallyEdited.value = batchForm.section_prefix !== suggestedPrefix.value;
};

// The live "Sections to be created" preview — fetched from the server
// (previewBatch) so the next-available-letter logic always matches
// what save will actually do, rather than duplicating that logic in
// JS and risking it drifting out of sync.
const previewSections = ref([]); // [{ section_code, estimated_students }]
const previewLoading = ref(false);
const previewError = ref('');
const nameErrors = ref({}); // index -> message, from server validation on save

const canPreview = computed(
    () =>
        !!batchForm.academic_year &&
        !!batchForm.semester &&
        !!batchForm.major_id &&
        !!batchForm.year_level &&
        (batchForm.section_type !== 'Regular' || !!batchForm.curriculum_id) &&
        !!batchForm.section_prefix &&
        batchForm.number_of_blocks >= 1 &&
        batchForm.estimated_students_per_block >= 1,
);

let previewDebounce = null;

// Reads Laravel's XSRF-TOKEN cookie (URL-decoded) — same pattern as
// TimeRecommendationSelector.vue / RoomRecommendationSelector.vue /
// FacultyRecommendationSelector.vue. Laravel refreshes this cookie on
// every response, so it self-heals as long as the browser has made
// any request recently — unlike the static <meta name="csrf-token">
// value below, which is baked into the page at the last full load and
// goes stale (causing a 419 "CSRF token mismatch") after the session
// changes underneath it, e.g. a `migrate:fresh` wiping the sessions
// table, or simply an idle tab outliving the session lifetime.
const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

const refreshPreview = () => {
    clearTimeout(previewDebounce);

    if (!canPreview.value) {
        previewSections.value = [];
        previewError.value = '';
        return;
    }

    previewDebounce = setTimeout(async () => {
        previewLoading.value = true;
        previewError.value = '';
        try {
            const response = await fetch(route('scheduling.sections.preview-batch'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    major_id: batchForm.major_id,
                    section_type: batchForm.section_type,
                    curriculum_id: batchForm.curriculum_id,
                    year_level: batchForm.year_level,
                    academic_year: batchForm.academic_year,
                    semester: batchForm.semester,
                    section_prefix: batchForm.section_prefix,
                    number_of_blocks: batchForm.number_of_blocks,
                    estimated_students_per_block: batchForm.estimated_students_per_block,
                    // Irregular's single-count field — reuses the same
                    // batchForm value as estimated_students_per_block
                    // (relabeled "Estimated Students" in the template
                    // for Irregular) so there's only one input to keep
                    // in sync; the backend picks whichever key its
                    // section_type branch actually needs.
                    estimated_students: batchForm.estimated_students_per_block,
                }),
            });

            if (!response.ok) {
                // Surface the server's actual validation message instead of
                // a generic one — a 422 from PreviewSectionBatchRequest
                // carries a specific reason (e.g. "The selected prospectus
                // does not belong to the selected program"), and hiding it
                // makes the real cause impossible to see from the UI.
                let serverMessage = '';
                try {
                    const errorBody = await response.json();
                    const firstFieldErrors = errorBody?.errors ? Object.values(errorBody.errors)[0] : null;
                    serverMessage = (Array.isArray(firstFieldErrors) ? firstFieldErrors[0] : null)
                        ?? errorBody?.message
                        ?? '';
                } catch (parseError) {
                    // Response wasn't JSON (e.g. a 500 HTML error page) —
                    // fall through to the generic message below.
                }

                previewError.value = serverMessage
                    ? `Could not generate a preview — ${serverMessage}`
                    : `Could not generate a preview — check the fields above. (HTTP ${response.status})`;
                previewSections.value = [];
                return;
            }

            const data = await response.json();
            previewSections.value = data.sections ?? [];
            nameErrors.value = {};
        } catch (e) {
            previewError.value = 'Could not reach the server to generate a preview.';
        } finally {
            previewLoading.value = false;
        }
    }, 350);
};

watch(
    () => [
        batchForm.academic_year,
        batchForm.semester,
        batchForm.section_type,
        batchForm.major_id,
        batchForm.curriculum_id,
        batchForm.year_level,
        batchForm.section_prefix,
        batchForm.number_of_blocks,
        batchForm.estimated_students_per_block,
    ],
    refreshPreview,
);

// Detect duplicate names the admin typed in manually within the
// preview list itself (server also re-checks this, and against the
// database, on save).
const previewDuplicates = computed(() => {
    const seen = new Map();
    previewSections.value.forEach((row, index) => {
        const key = (row.section_code || '').trim().toUpperCase();
        if (!key) return;
        if (seen.has(key)) {
            seen.get(key).push(index);
        } else {
            seen.set(key, [index]);
        }
    });

    const dupIndexes = new Set();
    for (const indexes of seen.values()) {
        if (indexes.length > 1) {
            indexes.forEach((i) => dupIndexes.add(i));
        }
    }
    return dupIndexes;
});

const hasBlankNames = computed(() => previewSections.value.some((row) => !(row.section_code || '').trim()));

const canSaveBatch = computed(
    () =>
        previewSections.value.length > 0 &&
        previewDuplicates.value.size === 0 &&
        !hasBlankNames.value &&
        !previewLoading.value,
);

const openAdd = () => {
    editingSection.value = null;
    batchForm.reset();
    batchForm.clearErrors();
    prefixManuallyEdited.value = false;
    previewSections.value = [];
    previewError.value = '';
    nameErrors.value = {};
    addSectionVisible.value = true;
};

const closeAddSection = () => {
    addSectionVisible.value = false;
    batchForm.reset();
    batchForm.clearErrors();
    prefixManuallyEdited.value = false;
    previewSections.value = [];
    nameErrors.value = {};
};

const onSaveBatch = () => {
    if (batchForm.processing || !canSaveBatch.value) {
        return;
    }

    nameErrors.value = {};

    batchForm
        .transform((data) => ({
            major_id: data.major_id,
            section_type: data.section_type,
            curriculum_id: data.curriculum_id,
            year_level: data.year_level,
            academic_year: data.academic_year,
            semester: data.semester,
            status: data.status,
            remarks: data.remarks,
            sections: previewSections.value.map((row) => ({
                section_code: (row.section_code || '').trim(),
                estimated_students: row.estimated_students,
            })),
        }))
        .post(route('scheduling.sections.store-batch'), {
            preserveScroll: true,
            onSuccess: () => {
                const count = previewSections.value.length;
                closeAddSection();
                Swal.fire({
                    title: count === 1 ? 'Section saved' : 'Sections saved',
                    text:
                        count === 1
                            ? 'The section was created successfully.'
                            : `${count} sections were created successfully.`,
                    icon: 'success',
                    confirmButtonColor: '#16A34A',
                });
                onRefresh();
            },
            onError: (errors) => {
                // Map "sections.0.section_code" style errors back onto
                // the matching preview row.
                const mapped = {};
                Object.entries(errors).forEach(([key, message]) => {
                    const match = key.match(/^sections\.(\d+)\.section_code$/);
                    if (match) {
                        mapped[match[1]] = message;
                    }
                });
                nameErrors.value = mapped;


                toast.add({
                    severity: 'warn',
                    summary: 'Check the sections list',
                    detail: 'Please fix the highlighted section names and try again.',
                    life: 3500,
                });
            },
        });
};

const removePreviewRow = (index) => {
    previewSections.value.splice(index, 1);
};

const onDeleteSection = (section) => {
    Swal.fire({
        title: 'Delete this section?',
        text: `${section.section_name} will be permanently deleted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('scheduling.sections.destroy', section.id), {
                preserveScroll: true,
                onSuccess: () => onRefresh(),
            });
        }
    });
};

const onFinalizeSection = (section) => {
    Swal.fire({
        title: 'Finalize this schedule?',
        html: `<strong>${section.section_name}</strong>'s schedule will be locked — Room Grid, manual edits, and Auto Generate will all be blocked until an Admin/Registrar unlocks it again.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#2563EB',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, finalize it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.post(route('scheduling.sections.finalize', section.id), {}, {
                preserveScroll: true,
                onSuccess: () => onRefresh(),
                onError: () => {
                    toast.add({
                        severity: 'error',
                        summary: 'Could not finalize',
                        detail: 'Something went wrong finalizing this schedule. Please try again.',
                        life: 4000,
                    });
                },
            });
        }
    });
};

const onUnlockSection = (section) => {
    Swal.fire({
        title: 'Unlock this schedule?',
        input: 'text',
        inputLabel: `Reason for unlocking ${section.section_name}`,
        inputPlaceholder: 'e.g. Correcting a room conflict flagged by the Dean',
        inputValidator: (value) => (!value ? 'A reason is required.' : undefined),
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563EB',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Unlock',
    }).then((result) => {
        if (result.isConfirmed) {
            router.post(route('scheduling.sections.unlock', section.id), { reason: result.value }, {
                preserveScroll: true,
                onSuccess: () => onRefresh(),
                onError: () => {
                    toast.add({
                        severity: 'error',
                        summary: 'Could not unlock',
                        detail: 'Something went wrong unlocking this schedule. Please try again.',
                        life: 4000,
                    });
                },
            });
        }
    });
};
</script>

<template>
    <Head title="Sections" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Sections</span>
        </template>

        <div class="max-w-7xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
            <!-- Page Title -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2 text-[#1E293B]">
                    Sections
                    <InfoPopover
                        title="Sections"
                        :paragraphs="[
                            'Academic sections (blocks of students) used when building schedules for the selected academic term.',
                        ]"
                        :bullets="[
                            'Irregular sections don\'t follow the standard curriculum flow and may need subjects merged in from a regular section.',
                            'Scheduling status tracks how many of a section\'s subjects have been assigned a room, faculty, and time.',
                            '🔒 A finalized section\'s schedule is locked from normal editing — only Admin/Registrar can unlock it.',
                            'Click a row to open that section\'s subjects and manage its schedule.',
                        ]"
                    />
                </h1>
                <p class="mt-1 text-slate-500">
                    Manage academic sections used for class scheduling.
                </p>
            </div>

            <div class="neu-card rounded-2xl transition-colors duration-300">
            <Card
                class="!rounded-2xl !bg-transparent !border-0 !shadow-none"
                :pt="{ body: { class: '!bg-transparent' } }"
            >
                <template #content>
                    <!-- Top Toolbar -->
                    <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3 neu-form">
                        <template #start>
                            <span class="relative w-full sm:w-80">
                                <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <InputText
                                    v-model="search"
                                    placeholder="Search by code, name, major or year"
                                    class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                    :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                />
                            </span>
                            <Select
                                v-model="selectedTerm"
                                :options="termOptions"
                                optionLabel="label"
                                optionValue="value"
                                class="w-full sm:w-64"
                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                @change="onTermChange"
                            >
                                <template #option="{ option }">
                                    <span class="flex items-center gap-2">
                                        {{ option.label }}
                                        <Tag
                                            v-if="option.status === 'Archived'"
                                            value="Archived"
                                            severity="warn"
                                            class="!text-[10px] !py-0.5"
                                        />
                                    </span>
                                </template>
                            </Select>
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
                                <Button label="Add Section" icon="pi pi-plus" severity="success" @click="openAdd" />
                            </div>
                        </template>
                    </Toolbar>

                    <!-- Sections Table -->
                    <DataTable
                        :value="sections.data"
                        :loading="loading"
                        dataKey="id"
                        class="neu-inset neu-table rounded-xl overflow-hidden"
                        :class="isDark ? 'neu-table-dark' : ''"
                        stripedRows
                        responsiveLayout="scroll"
                        lazy
                        paginator
                        :rows="sections.per_page"
                        :totalRecords="sections.total"
                        :first="(sections.current_page - 1) * sections.per_page"
                        rowHover
                        :rowClass="() => 'cursor-pointer'"
                        @page="onPage"
                        @row-click="onRowClick"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No sections found.</p>
                                <p class="text-slate-400 text-sm mt-1">
                                    Click "Add Section" to create your first section.
                                </p>
                                <Button
                                    label="Add Section"
                                    icon="pi pi-plus"
                                    severity="success"
                                    class="mt-3"
                                    @click="openAdd"
                                />
                            </div>
                        </template>

                        <Column field="section_name" header="Section Name" style="width: 10rem" />
                        <Column header="Type" style="width: 7rem">
                            <template #body="{ data }">
                                <Tag
                                    :value="data.section_type || 'Regular'"
                                    :severity="data.section_type === 'Irregular' ? 'warn' : 'info'"
                                />
                            </template>
                        </Column>
                        <Column header="Program" style="width: 10rem">
                            <template #body="{ data }">
                                {{ data.major?.name || '—' }}
                            </template>
                        </Column>
                        <Column header="Prospectus" style="width: 12rem">
                            <template #body="{ data }">
                                {{ data.curriculum?.code || '—' }}
                            </template>
                        </Column>
                        <Column header="Year Level" style="width: 9rem">
                            <template #body="{ data }">
                                {{ data.year_level }}
                            </template>
                        </Column>
                        <Column header="Academic Year" style="width: 9rem">
                            <template #body="{ data }">
                                {{ data.academic_year }}
                            </template>
                        </Column>
                        <Column header="Est. Students" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.estimated_students }}
                            </template>
                        </Column>
                        <Column style="width: 9rem">
                            <template #header>
                                <span class="flex items-center gap-1">
                                    Status
                                    <InfoPopover
                                        title="Section Status"
                                        :bullets="[
                                            'Active — currently used for scheduling.',
                                            'Inactive — kept for historical records.',
                                            '🔒 Lock icon — this section is finalized; Admin/Registrar can unlock it to make corrections.',
                                        ]"
                                        width="w-64"
                                    />
                                </span>
                            </template>
                            <template #body="{ data }">
                                <div class="flex items-center gap-1.5">
                                    <Tag
                                        :value="data.status"
                                        :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                    />
                                    <i
                                        v-if="data.is_finalized"
                                        class="pi pi-lock text-amber-500"
                                        v-tooltip.top="`Finalized${data.finalized_at ? ' ' + new Date(data.finalized_at).toLocaleDateString() : ''}`"
                                    ></i>
                                </div>
                            </template>
                        </Column>
                        <Column style="width: 11rem">
                            <template #header>
                                <span class="flex items-center gap-1">
                                    Scheduling
                                    <InfoPopover
                                        title="Scheduling Status"
                                        :bullets="[
                                            'No Subjects Yet — the section has no subjects assigned from the curriculum.',
                                            'Not Scheduled — subjects exist but none have a room, faculty, or time yet.',
                                            'Partially Scheduled — some subjects are scheduled, others still need it.',
                                            'Fully Scheduled — every subject has a complete schedule.',
                                        ]"
                                        width="w-72"
                                    />
                                </span>
                            </template>
                            <template #body="{ data }">
                                <Tag
                                    v-if="data.total_subjects_count === 0"
                                    value="No Subjects Yet"
                                    severity="secondary"
                                />
                                <Tag
                                    v-else-if="data.assigned_subjects_count === 0"
                                    value="Not Scheduled"
                                    severity="secondary"
                                />
                                <Tag
                                    v-else-if="data.assigned_subjects_count < data.total_subjects_count"
                                    :value="`Partially Scheduled (${data.assigned_subjects_count}/${data.total_subjects_count})`"
                                    severity="warn"
                                />
                                <Tag
                                    v-else
                                    :value="`Fully Scheduled (${data.assigned_subjects_count}/${data.total_subjects_count})`"
                                    severity="success"
                                />
                            </template>
                        </Column>
                        <Column header="Actions" style="width: 15rem">
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <Button
                                        icon="pi pi-book"
                                        text
                                        rounded
                                        severity="info"
                                        size="small"
                                        aria-label="Manage Subjects"
                                        @click.stop="goToSectionSubjects(data)"
                                    />
                                    <Button
                                        icon="pi pi-pencil"
                                        text
                                        rounded
                                        severity="secondary"
                                        size="small"
                                        aria-label="Edit"
                                        @click.stop="openEdit(data)"
                                    />
                                    <Button
                                        v-if="canManageFinalization && !data.is_finalized && data.total_subjects_count > 0 && data.assigned_subjects_count === data.total_subjects_count"
                                        icon="pi pi-lock"
                                        text
                                        rounded
                                        severity="warn"
                                        size="small"
                                        aria-label="Finalize Schedule"
                                        v-tooltip.top="'Finalize schedule'"
                                        @click.stop="onFinalizeSection(data)"
                                    />
                                    <Button
                                        v-if="canManageFinalization && data.is_finalized"
                                        icon="pi pi-lock-open"
                                        text
                                        rounded
                                        severity="warn"
                                        size="small"
                                        aria-label="Unlock Schedule"
                                        v-tooltip.top="'Unlock schedule'"
                                        @click.stop="onUnlockSection(data)"
                                    />
                                    <Button
                                        icon="pi pi-trash"
                                        text
                                        rounded
                                        severity="danger"
                                        size="small"
                                        aria-label="Delete"
                                        @click.stop="onDeleteSection(data)"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
            </div>
        </div>

        <!-- Add Section Dialog (batch generation flow) -->
        <Dialog
            v-model:visible="addSectionVisible"
            modal
            header="Add Section"
            :style="{ width: '760px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="darkDialogPt"
            @hide="closeAddSection"
        >
            <form class="flex flex-col gap-5 neu-form" @submit.prevent="onSaveBatch">
                <!-- Academic Information -->
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-2">
                        Academic Information
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4">
                        <div class="flex flex-col gap-1">
                            <label for="batch_academic_year" class="text-sm font-medium text-slate-700">
                                Academic Year <span class="text-red-500">*</span>
                            </label>
                            <Select
                                id="batch_academic_year"
                                v-model="batchForm.academic_year"
                                :options="academicYearOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="e.g. 2026-2027"
                                :invalid="!!batchForm.errors.academic_year"
                                class="w-full"
                            />
                            <small v-if="batchForm.errors.academic_year" class="text-red-500">
                                {{ batchForm.errors.academic_year }}
                            </small>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="batch_semester" class="text-sm font-medium text-slate-700">
                                Semester <span class="text-red-500">*</span>
                            </label>
                            <Select
                                id="batch_semester"
                                v-model="batchForm.semester"
                                :options="batchSemesterOptions"
                                optionLabel="label"
                                optionValue="value"
                                :disabled="!batchForm.academic_year"
                                :placeholder="batchForm.academic_year ? 'e.g. 1st Semester' : 'Select an academic year first'"
                                :invalid="!!batchForm.errors.semester"
                                class="w-full"
                            />
                            <small v-if="batchForm.errors.semester" class="text-red-500">
                                {{ batchForm.errors.semester }}
                            </small>
                        </div>

                        <div class="flex flex-col gap-1 sm:col-span-2">
                            <label for="batch_section_type" class="text-sm font-medium text-slate-700">
                                Section Type <span class="text-red-500">*</span>
                            </label>
                            <Select
                                id="batch_section_type"
                                v-model="batchForm.section_type"
                                :options="sectionTypeOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Select section type"
                                :invalid="!!batchForm.errors.section_type"
                                class="w-full"
                            />
                            <small v-if="batchForm.errors.section_type" class="text-red-500">
                                {{ batchForm.errors.section_type }}
                            </small>
                            <p v-else class="text-xs text-slate-400">
                                Irregular sections have subjects scheduled one at a time — Auto Generate will try to
                                merge each one into a compatible Regular section's class before creating an
                                independent schedule.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Program Information -->
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-2">
                        Program Information
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4">
                        <div class="flex flex-col gap-1 sm:col-span-2">
                            <label for="batch_major_id" class="text-sm font-medium text-slate-700">
                                College / Program <span class="text-red-500">*</span>
                            </label>
                            <Select
                                id="batch_major_id"
                                v-model="batchForm.major_id"
                                :options="activeMajors"
                                optionLabel="name"
                                optionValue="id"
                                filter
                                placeholder="e.g. Bachelor of Science in Information Technology (BSIT)"
                                :invalid="!!batchForm.errors.major_id"
                                class="w-full"
                            />
                            <small v-if="batchForm.errors.major_id" class="text-red-500">
                                {{ batchForm.errors.major_id }}
                            </small>
                            <small v-else-if="scopedCollegeId && !hasNoAssignedCollege" class="text-slate-400">
                                Showing programs for your assigned College only.
                            </small>
                            <small v-else-if="hasNoAssignedCollege" class="text-red-500">
                                Your account has no assigned College yet, so no programs are available. Contact an
                                Administrator.
                            </small>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="batch_year_level" class="text-sm font-medium text-slate-700">
                                Year Level <span class="text-red-500">*</span>
                            </label>
                            <Select
                                id="batch_year_level"
                                v-model="batchForm.year_level"
                                :options="yearLevelOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="e.g. 1st Year"
                                :invalid="!!batchForm.errors.year_level"
                                class="w-full"
                            />
                            <small v-if="batchForm.errors.year_level" class="text-red-500">
                                {{ batchForm.errors.year_level }}
                            </small>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="batch_curriculum_id" class="text-sm font-medium text-slate-700">
                                Prospectus
                                <span v-if="batchForm.section_type === 'Regular'" class="text-red-500">*</span>
                                <span v-else class="text-slate-400 font-normal">(optional / reference)</span>
                            </label>
                            <Select
                                id="batch_curriculum_id"
                                v-model="batchForm.curriculum_id"
                                :options="filteredProspectuses"
                                optionLabel="label"
                                optionValue="value"
                                filter
                                showClear
                                :disabled="!batchForm.major_id"
                                :placeholder="batchForm.major_id ? 'Select a prospectus' : 'Select a program first'"
                                :invalid="!!batchForm.errors.curriculum_id"
                                class="w-full"
                            />
                            <small v-if="batchForm.errors.curriculum_id" class="text-red-500">
                                {{ batchForm.errors.curriculum_id }}
                            </small>
                            <p v-else-if="batchForm.section_type === 'Irregular'" class="text-xs text-slate-400">
                                Irregular sections don't have to follow one Prospectus — subjects are added manually
                                on the section's Manage Subjects page.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Section Generation -->
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-2">
                        Section Generation
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-x-5 gap-y-4">
                        <div class="flex flex-col gap-1">
                            <label for="batch_prefix" class="text-sm font-medium text-slate-700">
                                Section Prefix <span class="text-red-500">*</span>
                            </label>
                            <InputText
                                id="batch_prefix"
                                v-model="batchForm.section_prefix"
                                :placeholder="batchForm.section_type === 'Regular' ? 'e.g. BSIT-1' : 'e.g. BSIT-4-IRREG'"
                                :invalid="!!batchForm.errors.section_prefix"
                                class="w-full"
                                @input="onPrefixInput"
                            />
                            <small v-if="batchForm.errors.section_prefix" class="text-red-500">
                                {{ batchForm.errors.section_prefix }}
                            </small>
                            <small v-else class="text-slate-400">
                                Auto-suggested from Program + Year Level — edit freely if your school uses a
                                different convention.
                            </small>
                        </div>

                        <div v-if="batchForm.section_type === 'Regular'" class="flex flex-col gap-1">
                            <label for="batch_blocks" class="text-sm font-medium text-slate-700">
                                Number of Blocks <span class="text-red-500">*</span>
                            </label>
                            <InputNumber
                                id="batch_blocks"
                                v-model="batchForm.number_of_blocks"
                                :min="1"
                                :max="100"
                                showButtons
                                buttonLayout="horizontal"
                                :invalid="!!batchForm.errors.number_of_blocks"
                                class="w-full"
                                inputClass="w-full"
                            />
                            <small v-if="batchForm.errors.number_of_blocks" class="text-red-500">
                                {{ batchForm.errors.number_of_blocks }}
                            </small>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="batch_students" class="text-sm font-medium text-slate-700">
                                {{ batchForm.section_type === 'Regular' ? 'Est. Students per Block' : 'Estimated Students' }}
                                <span class="text-red-500">*</span>
                            </label>
                            <InputNumber
                                id="batch_students"
                                v-model="batchForm.estimated_students_per_block"
                                :min="1"
                                showButtons
                                buttonLayout="horizontal"
                                :invalid="!!batchForm.errors.estimated_students_per_block || !!batchForm.errors.estimated_students"
                                class="w-full"
                                inputClass="w-full"
                            />
                            <small v-if="batchForm.errors.estimated_students_per_block || batchForm.errors.estimated_students" class="text-red-500">
                                {{ batchForm.errors.estimated_students_per_block || batchForm.errors.estimated_students }}
                            </small>
                        </div>
                    </div>
                    <p class="text-xs text-slate-400 mt-2">
                        <template v-if="batchForm.section_type === 'Regular'">
                            Classly automatically generates the next available section letters (A, B, C, ...), skipping
                            any that already exist for this Program, Academic Year and Semester. You can edit the
                            generated names below before saving.
                        </template>
                        <template v-else>
                            An Irregular section is a single scheduling group, not a set of lettered blocks — the name
                            below is exactly what you typed as the Section Prefix. Edit it freely before saving if your
                            school uses a different naming convention.
                        </template>
                    </p>
                </div>

                <!-- Live Preview -->
                <div v-if="batchForm.section_prefix || previewSections.length" class="rounded-xl border border-slate-200 overflow-hidden">
                    <div class="bg-slate-50 px-4 py-2 border-b border-slate-200 flex items-center justify-between">
                        <span class="text-sm font-semibold text-slate-700">Sections to be created</span>
                        <i v-if="previewLoading" class="pi pi-spin pi-spinner text-slate-400"></i>
                    </div>

                    <p v-if="previewError" class="text-sm text-red-500 px-4 py-3">{{ previewError }}</p>

                    <p v-else-if="!canPreview" class="text-sm text-slate-400 px-4 py-3">
                        Fill in the fields above to preview the sections that will be created.
                    </p>

                    <p v-else-if="!previewSections.length && !previewLoading" class="text-sm text-slate-400 px-4 py-3">
                        No sections to preview yet.
                    </p>

                    <div v-else class="divide-y divide-slate-100">
                        <div
                            v-for="(row, index) in previewSections"
                            :key="index"
                            class="flex items-center gap-3 px-4 py-2"
                        >
                            <div class="flex-1 flex flex-col gap-1">
                                <InputText
                                    v-model="row.section_code"
                                    class="w-full"
                                    :invalid="previewDuplicates.has(index) || !!nameErrors[index] || !row.section_code?.trim()"
                                />
                                <small v-if="nameErrors[index]" class="text-red-500">{{ nameErrors[index] }}</small>
                                <small v-else-if="previewDuplicates.has(index)" class="text-red-500">
                                    This name is used more than once below.
                                </small>
                                <small v-else-if="!row.section_code?.trim()" class="text-red-500">
                                    Section name is required.
                                </small>
                            </div>
                            <InputNumber
                                v-model="row.estimated_students"
                                :min="1"
                                class="w-32"
                                inputClass="w-full"
                                suffix=" students"
                            />
                            <Button
                                icon="pi pi-times"
                                text
                                rounded
                                severity="danger"
                                size="small"
                                aria-label="Remove"
                                @click="removePreviewRow(index)"
                            />
                        </div>
                    </div>
                </div>

                <!-- Other -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4">
                    <div class="flex flex-col gap-1">
                        <label for="batch_status" class="text-sm font-medium text-slate-700">
                            Status <span class="text-red-500">*</span>
                        </label>
                        <Select
                            id="batch_status"
                            v-model="batchForm.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                        />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label for="batch_remarks" class="text-sm font-medium text-slate-700">Remarks</label>
                        <Textarea
                            id="batch_remarks"
                            v-model="batchForm.remarks"
                            autoResize
                            rows="1"
                            placeholder="Optional notes"
                            class="w-full"
                        />
                    </div>
                </div>

                <small v-if="batchForm.errors.sections" class="text-red-500">{{ batchForm.errors.sections }}</small>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeAddSection" />
                <Button
                    :label="previewSections.length > 1 ? `Create ${previewSections.length} Sections` : 'Create Section'"
                    icon="pi pi-check"
                    severity="success"
                    :loading="batchForm.processing"
                    :disabled="batchForm.processing || !canSaveBatch"
                    @click="onSaveBatch"
                />
            </template>
        </Dialog>

        <!-- Edit Section Dialog -->
        <Dialog
            v-model:visible="editSectionVisible"
            modal
            header="Edit Section"
            :style="{ width: '700px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="darkDialogPt"
            @hide="closeEditSection"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4 neu-form" @submit.prevent="onSaveSection">
                <!-- Section Code -->
                <div class="flex flex-col gap-1">
                    <label for="section_code" class="text-sm font-medium text-slate-700">
                        Section Name <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="section_code"
                        v-model="sectionForm.section_code"
                        placeholder="e.g. BSIT-1A"
                        :invalid="!!sectionForm.errors.section_code"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.section_code" class="text-red-500">
                        {{ sectionForm.errors.section_code }}
                    </small>
                </div>

                <!-- Section Type -->
                <div class="flex flex-col gap-1">
                    <label for="section_type" class="text-sm font-medium text-slate-700">
                        Section Type <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="section_type"
                        v-model="sectionForm.section_type"
                        :options="sectionTypeOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select section type"
                        :invalid="!!sectionForm.errors.section_type"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.section_type" class="text-red-500">
                        {{ sectionForm.errors.section_type }}
                    </small>
                    <p v-else class="text-xs text-slate-400">
                        Irregular sections have subjects scheduled one at a time — Auto Generate will try to merge
                        each one into a compatible Regular section's class before creating an independent schedule.
                    </p>
                </div>

                <!-- Major -->
                <div class="flex flex-col gap-1">
                    <label for="major_id" class="text-sm font-medium text-slate-700">
                        College / Program <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="major_id"
                        v-model="sectionForm.major_id"
                        :options="activeMajors"
                        optionLabel="name"
                        optionValue="id"
                        filter
                        placeholder="Select a program"
                        :invalid="!!sectionForm.errors.major_id"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.major_id" class="text-red-500">
                        {{ sectionForm.errors.major_id }}
                    </small>
                    <p v-else-if="scopedCollegeId && !hasNoAssignedCollege" class="text-xs text-slate-400">
                        Showing programs for your assigned College only.
                    </p>
                    <p v-else-if="hasNoAssignedCollege" class="text-xs text-red-500">
                        Your account has no assigned College yet, so no programs are available. Contact an
                        Administrator.
                    </p>
                </div>

                <!-- Curriculum -->
                <div class="flex flex-col gap-1">
                    <label for="curriculum_id" class="text-sm font-medium text-slate-700">
                        Prospectus <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="curriculum_id"
                        v-model="sectionForm.curriculum_id"
                        :options="filteredCurriculums"
                        optionLabel="label"
                        optionValue="value"
                        filter
                        :disabled="!sectionForm.major_id"
                        :placeholder="sectionForm.major_id ? 'Select a prospectus' : 'Select a program first'"
                        :invalid="!!sectionForm.errors.curriculum_id"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.curriculum_id" class="text-red-500">
                        {{ sectionForm.errors.curriculum_id }}
                    </small>
                </div>

                <!-- Year Level -->
                <div class="flex flex-col gap-1">
                    <label for="year_level" class="text-sm font-medium text-slate-700">
                        Year Level <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="year_level"
                        v-model="sectionForm.year_level"
                        :options="yearLevelOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select year level"
                        :invalid="!!sectionForm.errors.year_level"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.year_level" class="text-red-500">
                        {{ sectionForm.errors.year_level }}
                    </small>
                </div>

                <!-- Academic Year -->
                <div class="flex flex-col gap-1">
                    <label for="academic_year" class="text-sm font-medium text-slate-700">
                        Academic Year <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="academic_year"
                        v-model="sectionForm.academic_year"
                        :options="editAcademicYearOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select academic year"
                        :invalid="!!sectionForm.errors.academic_year"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.academic_year" class="text-red-500">
                        {{ sectionForm.errors.academic_year }}
                    </small>
                </div>

                <!-- Semester -->
                <div class="flex flex-col gap-1">
                    <label for="semester" class="text-sm font-medium text-slate-700">
                        Semester <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="semester"
                        v-model="sectionForm.semester"
                        :options="editSemesterOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select semester"
                        :invalid="!!sectionForm.errors.semester"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.semester" class="text-red-500">
                        {{ sectionForm.errors.semester }}
                    </small>
                </div>

                <!-- Estimated Students -->
                <div class="flex flex-col gap-1">
                    <label for="estimated_students" class="text-sm font-medium text-slate-700">
                        Estimated Number of Students <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="estimated_students"
                        v-model="sectionForm.estimated_students"
                        :min="1"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!sectionForm.errors.estimated_students"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="sectionForm.errors.estimated_students" class="text-red-500">
                        {{ sectionForm.errors.estimated_students }}
                    </small>
                </div>

                <!-- Status -->
                <div class="flex flex-col gap-1">
                    <label for="status" class="text-sm font-medium text-slate-700">
                        Status <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="status"
                        v-model="sectionForm.status"
                        :options="statusOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select status"
                        :invalid="!!sectionForm.errors.status"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.status" class="text-red-500">
                        {{ sectionForm.errors.status }}
                    </small>
                </div>

                <!-- Remarks -->
                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label for="remarks" class="text-sm font-medium text-slate-700">Remarks</label>
                    <Textarea
                        id="remarks"
                        v-model="sectionForm.remarks"
                        autoResize
                        rows="3"
                        placeholder="Optional notes about this section"
                        :invalid="!!sectionForm.errors.remarks"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.remarks" class="text-red-500">
                        {{ sectionForm.errors.remarks }}
                    </small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeEditSection" />
                <Button
                    label="Update Section"
                    icon="pi pi-check"
                    severity="success"
                    :loading="sectionForm.processing"
                    :disabled="sectionForm.processing"
                    @click="onSaveSection"
                />
            </template>
        </Dialog>
    </AppLayout>
</template>
<style scoped>
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (added conditionally via isDark) recolors the shared light-mode
   Tailwind utility classes and PrimeVue component chrome used
   throughout this page — including inside Dialogs, which PrimeVue
   teleports to <body> but keeps as one contiguous subtree, so these
   descendant selectors still reach them. */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.bg-white) { background-color: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.bg-slate-50) { background-color: rgba(255, 255, 255, 0.04) !important; }
.dark-scope :deep(.bg-slate-100) { background-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }

.dark-scope :deep(.p-card) { background: rgba(255, 255, 255, 0.06) !important; color: #F8FAFC; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-password-input),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect),
.dark-scope :deep(.p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }

.dark-scope :deep(.p-divider.p-divider-horizontal:before) { border-color: rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }

.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.04) !important; color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-datatable-tbody > tr) { background: transparent !important; color: #E2E8F0 !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped) { background: rgba(255, 255, 255, 0.03) !important; }
.dark-scope :deep(.p-datatable-tbody > tr > td) { border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover) { background: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.p-paginator) { background: transparent !important; color: #CBD5E1 !important; }

.dark-scope :deep(.p-menu) { background: #0F1730 !important; border-color: rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-menu .p-menu-item-link) { color: #E2E8F0 !important; }
.dark-scope :deep(.p-menu .p-menu-item-link:hover) { background: rgba(255, 255, 255, 0.06) !important; }
</style>