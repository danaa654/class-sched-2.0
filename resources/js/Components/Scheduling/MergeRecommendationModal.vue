<script setup>
/**
 * INTELLIGENT IRREGULAR SECTION SCHEDULING — "Merge Recommendation"
 * modal (Auto Generate Schedule review panel).
 *
 * Shows every compatible-or-not Regular section class considered for
 * one Irregular-section subject (see IrregularSectionMergeService),
 * with scores, room capacity impact, faculty, schedule, and — for
 * candidates that were disqualified — the specific reason why. The
 * Administrator can accept the recommended best match, pick a
 * different compatible candidate, or fall back to an independent
 * schedule for this subject.
 */
import { computed } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import ProgressSpinner from 'primevue/progressspinner';

const props = defineProps({
    visible: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    applying: { type: Boolean, default: false },
    subjectCode: { type: String, default: '' },
    subjectTitle: { type: String, default: '' },
    recommendation: { type: Object, default: null }, // { recommendation, best_match, candidates, independent_reason }
});

const emit = defineEmits(['update:visible', 'choose-candidate', 'choose-independent']);

const close = () => emit('update:visible', false);

const candidates = computed(() => props.recommendation?.candidates ?? []);
const bestMatchId = computed(() => props.recommendation?.best_match?.section_subject_id ?? null);

const formatTime = (value) => {
    if (!value) return '—';
    const [hourStr, minuteStr] = String(value).split(':');
    const hour = Number(hourStr);
    const minute = minuteStr ?? '00';
    const period = hour >= 12 ? 'pm' : 'am';
    const displayHour = ((hour + 11) % 12) + 1;
    return `${displayHour}:${minute} ${period}`;
};

const formatDays = (days) => (days ? String(days).split(',').join('/') : '—');
</script>

<template>
    <Dialog
        :visible="visible"
        @update:visible="(v) => emit('update:visible', v)"
        modal
        header="Merge Recommendation"
        :style="{ width: '760px' }"
        :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
        :draggable="false"
    >
        <div class="mb-4">
            <p class="font-semibold text-slate-800">
                {{ subjectCode }} <span class="text-slate-400 font-normal">— {{ subjectTitle }}</span>
            </p>
            <p class="text-xs text-slate-500 mt-1">
                Compatible Regular section classes for this subject — same/equivalent curriculum, major, academic
                year, and semester — evaluated for room capacity, conflicts, and faculty workload.
            </p>
        </div>

        <div v-if="loading" class="flex items-center justify-center py-12">
            <ProgressSpinner style="width: 40px; height: 40px" strokeWidth="4" />
        </div>

        <div v-else>
            <div
                v-if="recommendation?.recommendation === 'independent' && !candidates.length"
                class="rounded-xl border border-slate-200 bg-slate-50 p-4 mb-4 flex items-start gap-2"
            >
                <i class="pi pi-info-circle text-slate-500 mt-0.5"></i>
                <p class="text-sm text-slate-600">{{ recommendation?.independent_reason }}</p>
            </div>

            <div v-else-if="recommendation?.recommendation === 'independent'" class="rounded-xl border border-amber-200 bg-amber-50 p-4 mb-4 flex items-start gap-2">
                <i class="pi pi-exclamation-triangle text-amber-600 mt-0.5"></i>
                <p class="text-sm text-slate-700">{{ recommendation?.independent_reason }}</p>
            </div>

            <div v-if="candidates.length" class="space-y-3 mb-4">
                <div
                    v-for="candidate in candidates"
                    :key="candidate.section_subject_id"
                    class="border rounded-xl p-4"
                    :class="[
                        candidate.compatible ? 'border-slate-200' : 'border-slate-100 opacity-70',
                        candidate.section_subject_id === bestMatchId ? 'ring-2 ring-emerald-400 bg-emerald-50/40' : '',
                    ]"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold text-slate-800 flex items-center gap-2">
                                {{ candidate.section_code }}
                                <Tag
                                    v-if="candidate.section_subject_id === bestMatchId"
                                    value="Recommended"
                                    severity="success"
                                    class="!text-xs"
                                />
                                <Tag
                                    v-if="!candidate.compatible"
                                    value="Not Compatible"
                                    severity="danger"
                                    class="!text-xs"
                                />
                            </p>
                            <p class="text-xs text-slate-500 mt-0.5">{{ candidate.section_name }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold text-slate-800">{{ candidate.score }}%</p>
                            <p class="text-[10px] uppercase tracking-wide text-slate-400">Match Score</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-3 text-xs">
                        <div>
                            <p class="text-slate-400 uppercase tracking-wide">Faculty</p>
                            <p class="text-slate-700 font-medium">{{ candidate.faculty_name || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-slate-400 uppercase tracking-wide">Room</p>
                            <p class="text-slate-700 font-medium">{{ candidate.room_name || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-slate-400 uppercase tracking-wide">Schedule</p>
                            <p class="text-slate-700 font-medium">
                                {{ formatDays(candidate.days) }} · {{ formatTime(candidate.start_time) }}–{{ formatTime(candidate.end_time) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-slate-400 uppercase tracking-wide">Capacity</p>
                            <p class="text-slate-700 font-medium">
                                {{ candidate.projected_headcount }}/{{ candidate.capacity }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-3 space-y-1">
                        <p v-if="candidate.blocking_reason" class="text-xs text-red-600 flex items-start gap-1">
                            <i class="pi pi-times-circle mt-0.5"></i> {{ candidate.blocking_reason }}
                        </p>
                        <p
                            v-for="(reason, idx) in candidate.reasons"
                            :key="idx"
                            class="text-xs text-slate-500 flex items-start gap-1"
                        >
                            <i class="pi pi-check-circle mt-0.5 text-emerald-500"></i> {{ reason }}
                        </p>
                    </div>

                    <div class="mt-3 flex justify-end">
                        <Button
                            label="Merge Into This Section"
                            icon="pi pi-sitemap"
                            size="small"
                            :severity="candidate.section_subject_id === bestMatchId ? 'success' : 'secondary'"
                            :outlined="candidate.section_subject_id !== bestMatchId"
                            :disabled="!candidate.compatible"
                            :loading="applying"
                            @click="emit('choose-candidate', candidate)"
                        />
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <Button label="Close" severity="secondary" outlined @click="close" />
            <Button
                label="Create Independent Schedule Instead"
                icon="pi pi-bolt"
                severity="warning"
                outlined
                :loading="applying"
                @click="emit('choose-independent')"
            />
        </template>
    </Dialog>
</template>