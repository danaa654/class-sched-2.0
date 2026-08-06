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

        return [
            'total' => $targets->count(),
            'scheduled' => count($results),
            'results' => $results,
            'unresolved' => $unresolved,
            'message' => count($results) === $targets->count()
                ? count($results).' of '.$targets->count().' subjects scheduled. No conflicts detected.'
                : count($results).' of '.$targets->count().' subjects scheduled. '
                    .count($unresolved).' '.(count($unresolved) === 1 ? 'subject requires' : 'subjects require').' manual scheduling.',
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
        $subject = $sectionSubject->subject;

        $facultyRec = $this->recommendationService->recommendFaculty($subject, $section, $sectionSubject);
        $facultyCandidates = $facultyRec['recommendations'];

        if (empty($facultyCandidates)) {
            return $this->unresolved($sectionSubject, $facultyRec['message'] ?? 'No qualified or college-matched faculty available.');
        }

        $roomRec = $this->recommendationService->recommendRooms($subject, $section, $sectionSubject);
        $roomCandidates = $roomRec['recommendations'];

        if (empty($roomCandidates)) {
            return $this->unresolved($sectionSubject, 'No available room of the correct type was found for this subject.');
        }

        foreach (array_slice($facultyCandidates, 0, self::CANDIDATE_FACULTY) as $facultyCandidate) {
            // Teaching Load hard cap — never assign a subject that
            // would push this faculty member past their declared
            // max_teaching_units, even if they're otherwise the top
            // ranked candidate. RecommendationService already scores
            // load, but scoring alone doesn't stop an overload; this
            // is the hard block the spec asks for.
            if (! $this->withinTeachingLoad($facultyCandidate, $subject)) {
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
            'No conflict-free day/time combination could be found among the qualified faculty and available rooms.'
        );
    }

    /**
     * Faculty's current committed load (Scheduled + already
     * auto-generated rows) plus this subject's units must not exceed
     * their max_teaching_units.
     */
    private function withinTeachingLoad(array $facultyCandidate, Subject $subject): bool
    {
        if (! $facultyCandidate['max_teaching_units']) {
            return true;
        }

        $projectedLoad = $facultyCandidate['current_load'] + ($subject->units ?? 0);

        return $projectedLoad <= $facultyCandidate['max_teaching_units'];
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
            ]),
        ];
    }

    private function unresolved(SectionSubject $sectionSubject, string $reason): array
    {
        return [
            'success' => false,
            'result' => [
                'section_subject_id' => $sectionSubject->id,
                'subject_code' => $sectionSubject->subject->subject_code,
                'subject_title' => $sectionSubject->subject->subject_title,
                'reason' => $reason,
            ],
        ];
    }
}