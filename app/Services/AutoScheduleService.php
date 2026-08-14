<?php

namespace App\Services;

use App\Models\Section;
use App\Models\SectionSubject;
use App\Models\Subject;

/**
 * "⚡ Auto Generate Schedule" (Prompt 8.9).
 *
 * This is NOT a blind auto-scheduler — it is a bulk driver on top of
 * RecommendationService/ScheduleConflictService, the exact same engine
 * the per-row "Recommend" drawer already uses. For every currently
 * UNSCHEDULED SectionSubject in a Section it asks the Recommendation
 * Engine for ranked Faculty, ranked Rooms, and a conflict-free Time
 * slot, then walks the top candidates until it finds a combination
 * that is fully conflict-free (Faculty, Room, Section, Duplicate,
 * Load, Capacity) — never inventing an assignment the recommendation
 * engine itself wouldn't have suggested one row at a time.
 *
 * Each accepted assignment is written to the row immediately (flagged
 * is_auto_generated = true, Status stays 'Draft') rather than held in
 * memory. This is deliberate: it lets every later subject in the same
 * run reuse ScheduleConflictService/RecommendationService completely
 * unmodified — an already-generated row is, from the engine's point
 * of view, indistinguishable from a manually-saved one, so "Smart
 * Search" (try the next faculty / next room / next day) falls out of
 * the existing ranking + conflict-skip logic for free instead of a
 * second, parallel implementation of it here.
 *
 * Nothing here ever touches a row that already has a Faculty, Room,
 * Days, Start Time, or End Time — manually assigned rows are always
 * left completely alone unless the Registrar explicitly clears/
 * regenerates.
 */
class AutoScheduleService
{
    /** How many top-ranked Faculty candidates are tried per subject. */
    private const CANDIDATE_FACULTY = 4;

    /** How many top-ranked Room candidates are tried per subject. */
    private const CANDIDATE_ROOMS = 4;

    public function __construct(
        private readonly RecommendationService $recommendationService,
        private readonly ScheduleConflictService $conflictService,
        private readonly FacultyWorkloadService $workloadService,
        private readonly IrregularSectionMergeService $mergeService,
        private readonly SiblingSectionPatternService $siblingPatternService,
    ) {
    }

    /**
     * Generate schedules for every unscheduled Subject in the Section.
     * Returns a summary the frontend uses to render the review panel.
     */
    public function generate(Section $section): array
    {
        $section->loadMissing('major.department');

        $targets = $this->unscheduledRows($section);

        if ($targets->isEmpty()) {
            return [
                'total' => 0,
                'scheduled' => 0,
                'results' => [],
                'unresolved' => [],
                'message' => 'Every subject in this section already has a schedule assigned.',
            ];
        }

        $results = [];
        $unresolved = [];

        // Heaviest (highest-unit) subjects first — these are the
        // hardest to fit around everything else, so give them first
        // pick of Faculty/Room/Time before the schedule fills up.
        $targets = $targets->sortByDesc(fn (SectionSubject $row) => $row->subject->units ?? 0)->values();

        foreach ($targets as $sectionSubject) {
            $outcome = $this->generateOne($section, $sectionSubject);

            if ($outcome['success']) {
                $results[] = $outcome['result'];
            } else {
                $unresolved[] = $outcome['result'];
            }
        }

        $mergedCount = collect($results)->where('is_merged', true)->count();

        $message = count($results) === $targets->count()
            ? count($results).' of '.$targets->count().' subjects scheduled. No conflicts detected.'
            : count($results).' of '.$targets->count().' subjects scheduled. '
                .count($unresolved).' '.(count($unresolved) === 1 ? 'subject requires' : 'subjects require').' manual scheduling.';

        if ($mergedCount > 0) {
            $message .= ' '.$mergedCount.' '.($mergedCount === 1 ? 'subject was' : 'subjects were')
                .' merged into existing Regular section classes.';
        }

        return [
            'total' => $targets->count(),
            'scheduled' => count($results),
            'merged' => $mergedCount,
            'results' => $results,
            'unresolved' => $unresolved,
            'message' => $message,
        ];
    }

    /**
     * "Clear Generated Schedule" — reverts every row this engine
     * produced (is_auto_generated = true) back to an empty slot.
     * Rows the Registrar assigned or edited by hand are never touched
     * (is_auto_generated is cleared the moment a row is hand-edited
     * via the normal Save Schedule flow — see SectionSubjectController).
     */
    public function clear(Section $section): int
    {
        return $section->sectionSubjects()
            ->where('is_auto_generated', true)
            ->update([
                'faculty_id' => null,
                'room_id' => null,
                'days' => null,
                'start_time' => null,
                'end_time' => null,
                'status' => 'Draft',
                'is_auto_generated' => false,
                'auto_generated_meta' => null,
                'is_merged' => false,
                'merged_into_section_subject_id' => null,
                'merge_recommendation' => null,
            ]);
    }

    /**
     * "Regenerate" — clears every previously auto-generated row (but
     * never manually-assigned ones), then runs generate() again from
     * a clean slate.
     */
    public function regenerate(Section $section): array
    {
        $this->clear($section);

        return $this->generate($section);
    }

    /**
     * Only subjects with a completely empty schedule slot are ever
     * touched — this is the "don't overwrite manual assignments"
     * rule enforced at the query level, not just by convention.
     */
    private function unscheduledRows(Section $section)
    {
        return $section->sectionSubjects()
            ->whereNull('faculty_id')
            ->whereNull('room_id')
            ->whereNull('days')
            ->whereNull('start_time')
            ->whereNull('end_time')
            ->with('subject')
            ->get()
            ->filter(fn (SectionSubject $row) => $row->subject !== null)
            ->values();
    }

    /**
     * Find and persist the best conflict-free Faculty + Room + Time
     * combination for one Subject, trying the top-ranked candidates
     * from RecommendationService in order (STEP 1–4 of the spec) and
     * falling through to the next one whenever a combination turns
     * out to have a conflict — this is the "Smart Search" behavior.
     */
    private function generateOne(Section $section, SectionSubject $sectionSubject): array
    {
        // PRACTICUM / OJT — an explicitly non-room-based delivery
        // type (Subject -> Faculty Supervisor -> Off-Campus ->
        // Required Hours). Never enters the Faculty+Room+Time search
        // or the Irregular-section merge evaluation below — both of
        // those exist to fit a class into a physical Room, which a
        // Practicum/OJT placement never occupies.
        if ($sectionSubject->subject?->isPracticum()) {
            return $this->resolvePracticum($section, $sectionSubject);
        }

        // INTELLIGENT IRREGULAR SECTION SCHEDULING — for an Irregular
        // section, evaluate a merge into a compatible Regular
        // section's existing class BEFORE ever searching for an
        // independent Faculty+Room+Time combination. The merge
        // recommendation (and every candidate considered) is always
        // recorded on the row — including when the outcome is
        // "independent" — so the review panel and the "Merge
        // Recommendation" modal have something to show regardless of
        // which path this subject ended up taking.
        if ($section->isIrregular()) {
            $mergeOutcome = $this->mergeService->recommend($sectionSubject);

            if ($mergeOutcome['recommendation'] === 'merge' && $mergeOutcome['best_match']) {
                $host = SectionSubject::find($mergeOutcome['best_match']['section_subject_id']);

                if ($host) {
                    $this->mergeService->applyMerge($sectionSubject, $host, $mergeOutcome);

                    return $this->mergedResult($sectionSubject, $mergeOutcome);
                }
            }

            // No viable merge — fall through to the normal independent
            // search below, but keep the recommendation (with its
            // 'independent_reason') attached to the row so the
            // Administrator can see why merging wasn't possible.
            $sectionSubject->update(['merge_recommendation' => $mergeOutcome]);
        }

        return $this->searchIndependent($section, $sectionSubject);
    }

    /**
     * Public entry point for "Create Independent Schedule Instead" —
     * the Administrator has already declined every merge candidate
     * offered by the Merge Recommendation modal, so this skips the
     * merge evaluation entirely and goes straight to the same
     * Faculty/Room/Time search generateOne() would otherwise fall
     * through to. Safe to call on any SectionSubject, Irregular
     * section or not.
     */
    public function scheduleIndependently(Section $section, SectionSubject $sectionSubject): array
    {
        return $this->searchIndependent($section, $sectionSubject);
    }

    /**
     * The actual independent Faculty+Room+Time search — Sibling
     * Section Pattern Matching first, then the general
     * RecommendationService-ranked "Smart Search". Shared by
     * generateOne() (after its merge-evaluation gate) and the public
     * scheduleIndependently() entry point above, so both paths stay
     * in exact agreement about what "independent" means.
     */
    private function searchIndependent(Section $section, SectionSubject $sectionSubject): array
    {
        $subject = $sectionSubject->subject;

        // SIBLING SECTION PATTERN MATCHING — before ever consulting
        // the general Faculty/Room/Time ranking, check whether a
        // sibling Section of the same cohort (same Major, Curriculum,
        // Academic Year, Semester, Year Level — e.g. 4A/4B/4C/4D)
        // already has this exact Subject fully scheduled. If so,
        // reuse that Faculty, Room, and — critically — the sibling's
        // ACTUAL saved duration (which may have been manually
        // trimmed shorter than the Subject's declared hours), only
        // searching for a different Day. This keeps a cohort's
        // sections consistently taught by the same Faculty in the
        // same Room whenever possible, which is what the Registrar
        // wants far more often than whatever the general engine would
        // otherwise rank highest. Falls straight through to the
        // normal search below the moment no usable donor pattern
        // exists.
        $siblingPattern = $this->siblingPatternService->findPattern($sectionSubject);
        $siblingDiagnostics = $this->siblingPatternService->getDiagnostics();

        if ($siblingPattern) {
            $errors = $this->conflictService->validate([
                'section_id' => $section->id,
                'faculty_id' => $siblingPattern['faculty_id'],
                'room_id' => $siblingPattern['room_id'],
                'days' => $siblingPattern['days'],
                'start_time' => $siblingPattern['start_time'],
                'end_time' => $siblingPattern['end_time'],
            ], $sectionSubject->id);

            if (empty($errors)) {
                $faculty = \App\Models\Faculty::find($siblingPattern['faculty_id']);

                if ($faculty && ! $this->workloadService->wouldExceed($faculty, $subject, $sectionSubject->id)) {
                    return $this->applySiblingPattern($sectionSubject, $siblingPattern);
                }
            }
        }

        $facultyRec = $this->recommendationService->recommendFaculty($subject, $section, $sectionSubject);
        $facultyCandidates = $facultyRec['recommendations'];

        if (empty($facultyCandidates)) {
            return $this->unresolved($sectionSubject, $facultyRec['message'] ?? 'No qualified or college-matched faculty available.', [], $siblingDiagnostics);
        }

        $roomRec = $this->recommendationService->recommendRooms($subject, $section, $sectionSubject);
        $roomCandidates = $roomRec['recommendations'];

        if (empty($roomCandidates)) {
            $reasons = $roomRec['reasons'] ?? [];
            $detail = $reasons ? implode(' ', $reasons) : 'No available room of the correct type was found for this subject.';

            return $this->unresolved($sectionSubject, $detail, $reasons, $siblingDiagnostics);
        }

        foreach (array_slice($facultyCandidates, 0, self::CANDIDATE_FACULTY) as $facultyCandidate) {
            // Teaching Load hard cap — never assign a subject that
            // would push this faculty member past their declared
            // max_teaching_units, even if they're otherwise the top
            // ranked candidate. RecommendationService already scores
            // load, but scoring alone doesn't stop an overload; this
            // is the hard block the spec asks for.
            if (! $this->withinTeachingLoad($facultyCandidate, $subject, $sectionSubject)) {
                continue;
            }

            foreach (array_slice($roomCandidates, 0, self::CANDIDATE_ROOMS) as $roomCandidate) {
                // Room Capacity hard cap.
                $capacityNeeded = $sectionSubject->capacity ?? $section->estimated_students ?? 0;
                if ($capacityNeeded && $roomCandidate['capacity'] < $capacityNeeded) {
                    continue;
                }

                $timeRec = $this->recommendationService->recommendTimes(
                    $subject, $section, $facultyCandidate['id'], $roomCandidate['id'], $sectionSubject
                );

                $bestTime = $timeRec['recommendations'][0] ?? null;

                if (! $bestTime) {
                    // No open Day/Time for this exact Faculty+Room
                    // pairing — Smart Search moves on to the next
                    // Room, then the next Faculty, automatically via
                    // these loops.
                    continue;
                }

                // Final authoritative safety net — re-validate the
                // exact combination through the same
                // ScheduleConflictService the manual Save Schedule
                // button uses, so nothing generated here could ever
                // be rejected when the Registrar clicks Save.
                $errors = $this->conflictService->validate([
                    'section_id' => $section->id,
                    'faculty_id' => $facultyCandidate['id'],
                    'room_id' => $roomCandidate['id'],
                    'days' => $bestTime['days'],
                    'start_time' => $bestTime['start_time'],
                    'end_time' => $bestTime['end_time'],
                ], $sectionSubject->id);

                if (! empty($errors)) {
                    continue;
                }

                return $this->apply($sectionSubject, $facultyCandidate, $roomCandidate, $bestTime);
            }
        }

        return $this->unresolved(
            $sectionSubject,
            'No conflict-free day/time combination could be found among the qualified faculty and available rooms.',
            [],
            $siblingDiagnostics ?? []
        );
    }

    /**
     * FACULTY WORKLOAD VALIDATION — STEP 3 ("Remove faculty exceeding
     * workload"). Faculty's current committed load (Scheduled + Draft,
     * active semester only) plus this subject's load must not exceed
     * their Maximum Teaching Load. Delegates to FacultyWorkloadService
     * — the same hard-cap math Manual Assignment and Save Schedule
     * enforce — so Auto Generate can never produce a placement that
     * "Save Schedule" would turn around and reject.
     *
     * Auto Generate never offers an override: an Administrator can
     * only override the cap through Manual Assignment / Save Schedule,
     * never through the bulk generator.
     */
    private function withinTeachingLoad(array $facultyCandidate, Subject $subject, SectionSubject $sectionSubject): bool
    {
        $faculty = \App\Models\Faculty::find($facultyCandidate['id']);

        if (! $faculty) {
            return false;
        }

        return ! $this->workloadService->wouldExceed($faculty, $subject, $sectionSubject->id);
    }

    /**
     * Persist the winning combination and build the Recommendation
     * Score summary (Faculty %, Room %, Time %, reasons) the review
     * panel displays.
     */
    private function apply(SectionSubject $sectionSubject, array $faculty, array $room, array $time): array
    {
        $meta = [
            'faculty' => [
                'id' => $faculty['id'],
                'name' => $faculty['name'],
                'score' => $faculty['score'],
                'confidence' => $faculty['confidence'],
                'reasons' => $faculty['reasons'],
                'tier' => $faculty['tier'] ?? null,
                'selected_by_college_match' => $faculty['selected_by_college_match'] ?? false,
            ],
            'room' => [
                'id' => $room['id'],
                'name' => $room['name'],
                'score' => $room['score'],
                'confidence' => $room['confidence'],
                'reasons' => $room['reasons'],
                'utilization_percent' => $room['utilization_percent'] ?? null,
                'status_color' => $room['status_color'] ?? null,
                'explanation' => $room['explanation'] ?? null,
                'badge' => $room['badge'] ?? null,
                'match_tier' => $room['match_tier'] ?? null,
                'is_recommended' => $room['is_recommended'] ?? false,
                'recommendation_level' => $room['recommendation_level'] ?? null,
                'is_manual_override' => $room['is_manual_override'] ?? false,
            ],
            'time' => [
                'days' => $time['days'],
                'start_time' => $time['start_time'],
                'end_time' => $time['end_time'],
                'score' => $time['score'],
                'confidence' => $time['confidence'],
                'reasons' => $time['reasons'],
            ],
            'overall_score' => (int) round(($faculty['score'] + $room['score'] + $time['score']) / 3),
        ];

        $sectionSubject->update([
            'faculty_id' => $faculty['id'],
            'room_id' => $room['id'],
            'days' => implode(',', $time['days']),
            'start_time' => $time['start_time'],
            'end_time' => $time['end_time'],
            'status' => 'Draft',
            'is_auto_generated' => true,
            'auto_generated_meta' => $meta,
        ]);

        return [
            'success' => true,
            'result' => array_merge($meta, [
                'section_subject_id' => $sectionSubject->id,
                'subject_code' => $sectionSubject->subject->subject_code,
                'subject_title' => $sectionSubject->subject->subject_title,
                'is_merged' => false,
                'merge_recommendation' => $sectionSubject->merge_recommendation,
            ]),
        ];
    }

    /**
     * Persist a Faculty + Room + Days + Time combination copied from
     * a sibling Section's already-scheduled row (see
     * SiblingSectionPatternService), and build the same review-panel
     * shape apply() produces so the frontend needs no special-casing
     * — it just additionally sees `pattern_source` identifying which
     * sibling Section this assignment was copied from.
     */
    private function applySiblingPattern(SectionSubject $sectionSubject, array $pattern): array
    {
        $faculty = \App\Models\Faculty::find($pattern['faculty_id']);
        $room = \App\Models\Room::find($pattern['room_id']);

        $meta = [
            'faculty' => [
                'id' => $faculty->id,
                'name' => $faculty->full_name ?? $faculty->name ?? '',
                'score' => 100,
                'confidence' => 'High',
                'reasons' => ["Copied from {$pattern['donor_section_code']}, which already teaches this subject with this faculty member."],
            ],
            'room' => [
                'id' => $room->id,
                'name' => $room->room_name ?? $room->name ?? '',
                'score' => 100,
                'confidence' => 'High',
                'reasons' => ["Copied from {$pattern['donor_section_code']}'s existing schedule for this subject."],
            ],
            'time' => [
                'days' => $pattern['days'],
                'start_time' => $pattern['start_time'],
                'end_time' => $pattern['end_time'],
                'score' => 100,
                'confidence' => 'High',
                'reasons' => ["Same duration as {$pattern['donor_section_code']}'s schedule for this subject, on a different day to avoid conflicts."],
            ],
            'overall_score' => 100,
            'pattern_source' => [
                'donor_section_id' => $pattern['donor_section_id'],
                'donor_section_code' => $pattern['donor_section_code'],
            ],
        ];

        $sectionSubject->update([
            'faculty_id' => $pattern['faculty_id'],
            'room_id' => $pattern['room_id'],
            'days' => implode(',', $pattern['days']),
            'start_time' => $pattern['start_time'],
            'end_time' => $pattern['end_time'],
            'status' => 'Draft',
            'is_auto_generated' => true,
            'auto_generated_meta' => $meta,
        ]);

        return [
            'success' => true,
            'result' => array_merge($meta, [
                'section_subject_id' => $sectionSubject->id,
                'subject_code' => $sectionSubject->subject->subject_code,
                'subject_title' => $sectionSubject->subject->subject_title,
                'is_merged' => false,
                'merge_recommendation' => $sectionSubject->merge_recommendation,
            ]),
        ];
    }

    /**
     * Resolve a Practicum/OJT SectionSubject row. Faculty Supervisor
     * is optional (spec Section 4: "Faculty/adviser assignment should
     * still be supported"), so this simply picks the first Active,
     * qualified faculty member who wouldn't exceed their teaching
     * load if one exists — it never blocks on failing to find one.
     * No Room, Days, Start Time, or End Time are ever set: the row is
     * immediately marked Draft/scheduled with Days/Time left empty
     * and displayed by the frontend as "Off-Campus / OJT" instead of
     * a classroom (spec Section 4/6).
     */
    private function resolvePracticum(Section $section, SectionSubject $sectionSubject): array
    {
        $subject = $sectionSubject->subject;

        $facultyId = null;
        $facultyName = null;

        foreach ($subject->faculty()->where('status', 'Active')->get() as $candidate) {
            if (! $this->workloadService->wouldExceed($candidate, $subject, $sectionSubject->id)) {
                $facultyId = $candidate->id;
                $facultyName = $candidate->full_name ?? $candidate->name ?? null;
                break;
            }
        }

        $meta = [
            'delivery_type' => 'practicum',
            'faculty' => [
                'id' => $facultyId,
                'name' => $facultyName,
                'score' => $facultyId ? 100 : 0,
                'confidence' => $facultyId ? 'High' : 'None',
                'reasons' => $facultyId
                    ? ["Qualified, Active faculty supervisor assigned for {$subject->subject_code}."]
                    : ['No Active qualified faculty supervisor available yet — assign one manually.'],
            ],
            'room' => null,
            'time' => null,
            'deployment_type' => $subject->deployment_type,
            'required_hours' => $subject->required_hours,
            'overall_score' => $facultyId ? 100 : 60,
        ];

        $sectionSubject->update([
            'faculty_id' => $facultyId,
            'room_id' => null,
            'days' => null,
            'start_time' => null,
            'end_time' => null,
            'status' => 'Draft',
            'is_auto_generated' => true,
            'auto_generated_meta' => $meta,
        ]);

        return [
            'success' => true,
            'result' => array_merge($meta, [
                'section_subject_id' => $sectionSubject->id,
                'subject_code' => $subject->subject_code,
                'subject_title' => $subject->subject_title,
                'is_merged' => false,
                'is_practicum' => true,
                'merge_recommendation' => null,
            ]),
        ];
    }

    /**
     * Builds the review-panel result row for a subject that was
     * MERGED into an existing Regular section's class rather than
     * scheduled independently — "Merge into BSIT-4A" per the spec.
     */
    private function mergedResult(SectionSubject $sectionSubject, array $mergeOutcome): array
    {
        $host = $mergeOutcome['best_match'];

        return [
            'success' => true,
            'result' => [
                'section_subject_id' => $sectionSubject->id,
                'subject_code' => $sectionSubject->subject->subject_code,
                'subject_title' => $sectionSubject->subject->subject_title,
                'is_merged' => true,
                'merged_into_section_code' => $host['section_code'],
                'merge_recommendation' => $mergeOutcome,
                'overall_score' => $host['score'],
                'faculty' => ['name' => $host['faculty_name']],
                'room' => ['name' => $host['room_name']],
                'time' => [
                    'days' => explode(',', (string) $host['days']),
                    'start_time' => $host['start_time'],
                    'end_time' => $host['end_time'],
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $reasonDetails  Itemized bullet reasons (e.g. from
     *                                       RecommendationService::recommendRooms()'s
     *                                       'reasons' key) for the frontend's
     *                                       "⚠ No suitable room available" panel.
     *                                       Empty when the single $reason string
     *                                       already says everything (faculty/time
     *                                       cases untouched by this task).
     * @param  list<array<string, mixed>>  $siblingDiagnostics  The full trace from
     *                                       SiblingSectionPatternService::getDiagnostics()
     *                                       for this row — every donor considered and
     *                                       every Day candidate tried against it, with
     *                                       the exact conflict (Section/Faculty/Room)
     *                                       that rejected each one. Surfaced so the
     *                                       Registrar can see *why* a subject didn't
     *                                       inherit a sibling section's pattern instead
     *                                       of having to dig through the log file.
     */
    private function unresolved(SectionSubject $sectionSubject, string $reason, array $reasonDetails = [], array $siblingDiagnostics = []): array
    {
        return [
            'success' => false,
            'result' => [
                'section_subject_id' => $sectionSubject->id,
                'subject_code' => $sectionSubject->subject->subject_code,
                'subject_title' => $sectionSubject->subject->subject_title,
                'reason' => $reason,
                'reason_details' => $reasonDetails,
                'sibling_pattern_diagnostics' => $siblingDiagnostics,
                'merge_recommendation' => $sectionSubject->merge_recommendation,
            ],
        ];
    }
}