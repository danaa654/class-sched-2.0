<?php

namespace App\Services;

use App\Models\EdpCodeSequence;
use App\Models\SectionSubject;
use Illuminate\Support\Facades\DB;

/**
 * Generates EDP Codes for SectionSubject rows.
 *
 * Called the moment a Subject is placed into a Section — via manual
 * "Add Subject" or "Generate Curriculum Subjects" — not when its
 * schedule is later filled in. A row keeps its code for life once
 * minted; Faculty/Room/Days/Time can still be edited freely after.
 *
 * Format: {Major Prefix}-{YY}{Semester}{YearLevel}{Sequence}
 *   e.g. IT-2611001
 *
 *   Major Prefix = Major.code (never hardcoded)
 *   YY           = first year of the Section's academic_year ("2026-2027" -> "26")
 *   Semester     = 1 First Semester, 2 Second Semester, 3 Summer
 *   Year Level   = 1 First Year, 2 Second Year, 3 Third Year, 4 Fourth Year
 *   Sequence     = 3-digit running number, unique within Major + Academic
 *                  Year + Semester + Year Level (each year level starts
 *                  its own count at 001, matching how the Registrar's
 *                  EDP ledger has always numbered them)
 *
 * The running number itself lives in edp_code_sequences, not in a
 * COUNT() over section_subjects — so deleting a scheduled row can
 * never free its number back up for reuse.
 */
class EDPCodeService
{
    /**
     * @var array<string, string>
     */
    private const SEMESTER_CODES = [
        'First Semester' => '1',
        'Second Semester' => '2',
        'Summer' => '3',
    ];

    /**
     * @var array<string, string>
     */
    private const YEAR_LEVEL_CODES = [
        'First Year' => '1',
        'Second Year' => '2',
        'Third Year' => '3',
        'Fourth Year' => '4',
    ];

    /**
     * Generate and persist the EDP Code for a SectionSubject, if (and
     * only if) it doesn't already have one. Existing codes are never
     * regenerated or modified — this is a no-op if edp_code is
     * already set.
     *
     * Returns the row's EDP Code (freshly generated, or the one it
     * already had), or null if it couldn't be generated (e.g. the
     * Section's Major has no code, or its Semester/Year Level don't
     * match the known set).
     */
    public function generateForSectionSubject(SectionSubject $sectionSubject): ?string
    {
        if (! empty($sectionSubject->edp_code)) {
            return $sectionSubject->edp_code;
        }

        $sectionSubject->loadMissing('section.major');
        $section = $sectionSubject->section;
        $major = $section?->major;

        if (! $major || empty($major->code)) {
            return null;
        }

        $semesterCode = self::SEMESTER_CODES[$section->semester] ?? null;
        $yearLevelCode = self::YEAR_LEVEL_CODES[$section->year_level] ?? null;

        if (! $semesterCode || ! $yearLevelCode || empty($section->academic_year)) {
            return null;
        }

        $yy = $this->academicYearShort($section->academic_year);

        $sequence = $this->nextSequence($major->id, $section->academic_year, $semesterCode, $yearLevelCode);

        $code = sprintf(
            '%s-%s%s%s%s',
            $major->code,
            $yy,
            $semesterCode,
            $yearLevelCode,
            str_pad((string) $sequence, 3, '0', STR_PAD_LEFT)
        );

        $sectionSubject->edp_code = $code;
        $sectionSubject->save();

        return $code;
    }

    /**
     * "2026-2027" -> "26". Falls back to whatever 4 digits it can
     * find if the string isn't in the usual YYYY-YYYY shape.
     */
    private function academicYearShort(string $academicYear): string
    {
        $startYear = (int) substr($academicYear, 0, 4);

        return str_pad((string) ($startYear % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Atomically claim the next sequence number for this
     * Major + Academic Year + Semester + Year Level scope. Row-locked
     * so two schedules saved at the same moment never get handed the
     * same number, and each year level's count starts independently
     * at 001 instead of continuing on from the previous year level.
     */
    private function nextSequence(int $majorId, string $academicYear, string $semesterCode, string $yearLevelCode): int
    {
        return DB::transaction(function () use ($majorId, $academicYear, $semesterCode, $yearLevelCode) {
            $scope = EdpCodeSequence::query()
                ->where('major_id', $majorId)
                ->where('academic_year', $academicYear)
                ->where('semester_code', $semesterCode)
                ->where('year_level_code', $yearLevelCode)
                ->lockForUpdate()
                ->first();

            if (! $scope) {
                // Guard against a race between the lookup above and this
                // insert: another request may have created the row first.
                $scope = EdpCodeSequence::query()->firstOrCreate(
                    [
                        'major_id' => $majorId,
                        'academic_year' => $academicYear,
                        'semester_code' => $semesterCode,
                        'year_level_code' => $yearLevelCode,
                    ],
                    ['last_sequence' => 0]
                );

                $scope = EdpCodeSequence::query()
                    ->whereKey($scope->id)
                    ->lockForUpdate()
                    ->first();
            }

            $scope->last_sequence += 1;
            $scope->save();

            return $scope->last_sequence;
        });
    }
}