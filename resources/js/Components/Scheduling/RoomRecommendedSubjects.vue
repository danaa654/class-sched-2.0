<script setup>
/**
 * Room Recommendation & Smart Auto-Scheduling — "Recommended
 * Subjects" panel embedded in the Room Details modal (Rooms/Index.vue).
 *
 * The Room page is the source of truth for these recommendations.
 * They are SOFT preferences only:
 *   - Saving one only stores the preference — it never creates or
 *     edits a schedule.
 *   - Auto Schedule / the Recommendation Engine read them as a
 *     scoring bonus (see RecommendationService::recommendRooms()),
 *     never a hard constraint. A recommended room can still be
 *     skipped if it fails capacity/type/availability/conflicts.
 *
 * Backed by:
 *   GET    scheduling.rooms.recommendations           list for a room
 *   GET    scheduling.rooms.recommendations.subjects   searchable picker
 *   POST   scheduling.rooms.recommendations.store       save (multi)
 *   DELETE scheduling.rooms.recommendations.destroy     remove one
 */
import { ref, computed, watch } from 'vue';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Checkbox from 'primevue/checkbox';
import Tag from 'primevue/tag';
import { useToast } from 'primevue/usetoast';

const props = defineProps({
    room: { type: Object, required: true }, // needs at least { id, room_code, room_name }
    canManage: { type: Boolean, default: true },
});

const toast = useToast();

const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

/* ------------------------------------------------------------------ */
/* Recommended Subjects list                                          */
/* ------------------------------------------------------------------ */
const loading = ref(false);
const recommendations = ref([]); // [{ recommendation_id, subject_id, subject_code, subject_title, category, units, major }]
const count = computed(() => recommendations.value.length);

async function loadRecommendations() {
    if (!props.room?.id) return;
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('scheduling.rooms.recommendations', props.room.id));
        recommendations.value = data.recommendations;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Could not load recommendations', life: 3000 });
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.room?.id,
    (id) => {
        if (id) loadRecommendations();
    },
    { immediate: true }
);

defineExpose({ reload: loadRecommendations, openAdd });

/* ------------------------------------------------------------------ */
/* Add Subject Recommendation modal                                   */
/* ------------------------------------------------------------------ */
const addVisible = ref(false);
const search = ref('');
const searching = ref(false);
const subjectOptions = ref([]); // subjects NOT yet recommended for this room, each flagged { natural_fit, fit_reason }
const selectedIds = ref(new Set());
const saving = ref(false);
let searchTimer = null;

const naturalFitSubjects = computed(() => subjectOptions.value.filter((s) => s.natural_fit));
const overrideSubjects = computed(() => subjectOptions.value.filter((s) => !s.natural_fit));
const selectedOverrideCount = computed(
    () => subjectOptions.value.filter((s) => !s.natural_fit && selectedIds.value.has(s.id)).length
);

function openAdd() {
    addVisible.value = true;
    search.value = '';
    selectedIds.value = new Set();
    fetchSubjects();
}

function closeAdd() {
    addVisible.value = false;
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(fetchSubjects, 250);
});

async function fetchSubjects() {
    if (!props.room?.id) return;
    searching.value = true;
    try {
        const { data } = await window.axios.get(route('scheduling.rooms.recommendations.subjects', props.room.id), {
            params: { search: search.value },
        });
        subjectOptions.value = data.subjects;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Could not load subjects', life: 3000 });
    } finally {
        searching.value = false;
    }
}

function toggleSubject(id) {
    const next = new Set(selectedIds.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selectedIds.value = next;
}

async function saveRecommendations() {
    if (selectedIds.value.size === 0) {
        toast.add({ severity: 'warn', summary: 'Select at least one subject', life: 2500 });
        return;
    }
    saving.value = true;
    try {
        await window.axios.post(
            route('scheduling.rooms.recommendations.store', props.room.id),
            { subject_ids: Array.from(selectedIds.value) },
            { headers: { 'X-XSRF-TOKEN': csrfToken() } }
        );
        toast.add({ severity: 'success', summary: 'Recommendation saved', life: 2500 });
        addVisible.value = false;
        await loadRecommendations();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Could not save recommendations', life: 3000 });
    } finally {
        saving.value = false;
    }
}

/* ------------------------------------------------------------------ */
/* Remove Recommendation confirmation                                 */
/* ------------------------------------------------------------------ */
const removeTarget = ref(null); // the recommendation row pending confirmation
const removing = ref(false);

function askRemove(row) {
    removeTarget.value = row;
}

const removeVisible = computed({
    get: () => !!removeTarget.value,
    set: (v) => {
        if (!v) removeTarget.value = null;
    },
});

async function confirmRemove() {
    if (!removeTarget.value) return;
    removing.value = true;
    try {
        await window.axios.delete(
            route('scheduling.rooms.recommendations.destroy', [props.room.id, removeTarget.value.recommendation_id]),
            { headers: { 'X-XSRF-TOKEN': csrfToken() } }
        );
        toast.add({ severity: 'success', summary: 'Recommendation removed', life: 2500 });
        removeTarget.value = null;
        await loadRecommendations();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Could not remove recommendation', life: 3000 });
    } finally {
        removing.value = false;
    }
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-2 gap-2 flex-wrap">
            <p class="text-sm font-semibold text-slate-700">Recommended Subjects</p>
            <Tag :value="`${count} subject${count === 1 ? '' : 's'} recommended`" severity="info" />
        </div>
        <p class="text-xs text-slate-400 mb-3">
            A soft preference read by Auto Schedule — it never overrides a room conflict, and a subject can still be assigned elsewhere.
        </p>

        <div v-if="loading" class="py-6 text-center text-slate-400 text-sm">
            <i class="pi pi-spin pi-spinner"></i>
            Loading recommendations…
        </div>

        <div v-else-if="recommendations.length === 0" class="rounded-xl border border-dashed border-slate-200 p-5 text-center">
            <p class="text-sm font-medium text-slate-600">No subject recommendations yet</p>
            <p class="text-xs text-slate-400 mt-1">
                Add subjects that should be prioritized for this room during automatic scheduling.
            </p>
        </div>

        <div v-else class="space-y-2">
            <div
                v-for="row in recommendations"
                :key="row.recommendation_id"
                class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2"
            >
                <div>
                    <p class="text-sm font-medium text-slate-700">{{ row.subject_code }} — {{ row.subject_title }}</p>
                    <p class="text-xs text-slate-400">{{ row.major ?? 'Shared / General Education' }} · {{ row.units }} units</p>
                    <div class="flex items-center gap-1.5 mt-1">
                        <Tag value="Preferred" severity="success" style="font-size: 10px" />
                        <Tag
                            v-if="row.is_manual_override"
                            value="Administrator Override"
                            severity="warning"
                            style="font-size: 10px"
                        />
                    </div>
                </div>
                <Button
                    v-if="canManage"
                    icon="pi pi-times"
                    text
                    rounded
                    severity="danger"
                    aria-label="Remove recommendation"
                    @click="askRemove(row)"
                />
            </div>
        </div>

        <!-- Add Subject Recommendation modal -->
        <Dialog
            v-model:visible="addVisible"
            modal
            header="Select Subjects for Room Recommendation"
            :style="{ width: '520px' }"
            :breakpoints="{ '640px': '95vw' }"
            :draggable="false"
            @hide="closeAdd"
        >
            <InputText v-model="search" placeholder="Search subject code or title" class="w-full mb-3" />

            <div v-if="searching" class="py-8 text-center text-slate-400 text-sm">
                <i class="pi pi-spin pi-spinner"></i>
                Searching…
            </div>

            <div v-else-if="subjectOptions.length === 0" class="py-8 text-center text-sm text-slate-400">
                No matching subjects found.
            </div>

            <div v-else class="max-h-80 overflow-y-auto space-y-3 pr-1">
                <!-- Naturally-suited subjects for this room's own College/Program + Room Type -->
                <div v-if="naturalFitSubjects.length">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-600 mb-1 px-1">
                        Suits this room
                    </p>
                    <label
                        v-for="subject in naturalFitSubjects"
                        :key="subject.id"
                        class="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-slate-50 cursor-pointer"
                    >
                        <Checkbox :modelValue="selectedIds.has(subject.id)" binary @update:modelValue="() => toggleSubject(subject.id)" />
                        <span class="text-sm text-slate-700">
                            {{ subject.subject_code }} — {{ subject.subject_title }}
                            <span class="text-xs text-slate-400">({{ subject.major ?? 'Shared' }} · {{ subject.units }} units)</span>
                            <span v-if="subject.other_recommended_rooms?.length" class="block text-[11px] text-sky-600">
                                Also recommended in {{ subject.other_recommended_rooms.map((r) => r.room_name).join(', ') }}
                            </span>
                        </span>
                    </label>
                </div>

                <!-- Cross-department / cross-type subjects — still selectable, but the pick becomes an explicit Administrator Override -->
                <div v-if="overrideSubjects.length">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-600 mb-1 px-1">
                        Other subjects (will be a manual override)
                    </p>
                    <label
                        v-for="subject in overrideSubjects"
                        :key="subject.id"
                        class="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-slate-50 cursor-pointer"
                    >
                        <Checkbox :modelValue="selectedIds.has(subject.id)" binary @update:modelValue="() => toggleSubject(subject.id)" />
                        <span class="text-sm text-slate-700">
                            {{ subject.subject_code }} — {{ subject.subject_title }}
                            <span class="text-xs text-slate-400">
                                ({{ subject.major ?? 'Shared' }} · {{ subject.units }} units)
                            </span>
                            <span class="block text-[11px] text-amber-600">{{ subject.fit_reason }}</span>
                            <span v-if="subject.other_recommended_rooms?.length" class="block text-[11px] text-sky-600">
                                Also recommended in {{ subject.other_recommended_rooms.map((r) => r.room_name).join(', ') }}
                            </span>
                        </span>
                    </label>
                </div>
            </div>

            <p v-if="selectedOverrideCount" class="text-xs text-amber-600 mt-2 px-1">
                {{ selectedOverrideCount }} selected subject{{ selectedOverrideCount === 1 ? '' : 's' }} will be saved as an Administrator Override (cross-department recommendation).
            </p>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeAdd" />
                <Button
                    label="Save Recommendations"
                    icon="pi pi-check"
                    :loading="saving"
                    :disabled="selectedIds.size === 0"
                    @click="saveRecommendations"
                />
            </template>
        </Dialog>

        <!-- Remove confirmation -->
        <Dialog
            v-model:visible="removeVisible"
            modal
            header="Remove Room Recommendation?"
            :style="{ width: '420px' }"
            :draggable="false"
        >
            <p class="text-sm text-slate-600" v-if="removeTarget">
                {{ removeTarget.subject_code }} will no longer be prioritized for {{ room.room_name }} during automatic scheduling.
            </p>
            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="removeTarget = null" />
                <Button label="Remove Recommendation" severity="danger" :loading="removing" @click="confirmRemove" />
            </template>
        </Dialog>
    </div>
</template>