<?php

namespace App\Services;

use App\Models\Section;
use Illuminate\Support\Str;

/**
 * Generates section "block" names for the Add Section flow.
 *
 * A Section Prefix (e.g. "BSIT-1") plus a Number of Blocks (e.g. 2)
 * becomes BSIT-1A, BSIT-1B, ... — reusing the existing `section_code`
 * / `section_name` columns rather than a new concept. Section Code and
 * Section Name are set to the same generated value; the registrar no
 * longer fills them in separately.
 *
 * This does NOT introduce a new "Prospectus" table. The existing
 * Curriculum/Curriculum Items models already represent the school's
 * prospectus — only the user-facing label changes.
 */
class SectionBatchGeneratorService
{
    /**
     * Work out the next available block letters for a prefix, skipping
     * any letter suffix that's already in use by an existing (non
     * soft-deleted) Section within the same academic context.
     *
     * "Academic context" here means Academic Year + Semester + Year
     * Level + Major (Program) — the same prefix can be reused across
     * different terms/year levels/programs without colliding.
     *
     * @return list<string> e.g. ['BSIT-1B', 'BSIT-1C']
     */
    public function nextBlockNames(
        string $prefix,
        int $numberOfBlocks,
        string $academicYear,
        string $semester,
        string $yearLevel,
        int $majorId,
        ?int $excludeSectionId = null,
    ): array {
        $existingCodes = Section::query()
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->where('year_level', $yearLevel)
            ->where('major_id', $majorId)
            ->where('section_code', 'like', $prefix.'%')
            ->when($excludeSectionId, fn ($query) => $query->where('id', '!=', $excludeSectionId))
            ->pluck('section_code');

        $usedLetters = $this->extractUsedLetters($prefix, $existingCodes->all());

        $generated = [];
        $ordinal = 1;

        while (count($generated) < $numberOfBlocks) {
            $letter = $this->letterForOrdinal($ordinal);

            if (! in_array($letter, $usedLetters, true)) {
                $generated[] = $prefix.$letter;
                $usedLetters[] = $letter;
            }

            $ordinal++;

            // Safety valve — should be unreachable in practice, but
            // avoids an infinite loop on a pathological input.
            if ($ordinal > 10000) {
                break;
            }
        }

        return $generated;
    }

    /**
     * Pull out just the letter suffixes (A, B, ... Z, AA, AB, ...) of
     * existing codes that start with the given prefix and are purely
     * alphabetic after it. Codes the registrar has manually renamed to
     * something else (e.g. "BSIT-1-Morning") simply won't match and
     * are ignored for letter-generation purposes — they still block
     * their own exact name via the normal uniqueness check.
     *
     * @param  list<string>  $existingCodes
     * @return list<string>
     */
    private function extractUsedLetters(string $prefix, array $existingCodes): array
    {
        $letters = [];

        foreach ($existingCodes as $code) {
            if (! Str::startsWith($code, $prefix)) {
                continue;
            }

            $suffix = strtoupper(substr($code, strlen($prefix)));

            if ($suffix !== '' && ctype_alpha($suffix)) {
                $letters[] = $suffix;
            }
        }

        return $letters;
    }

    /**
     * Spreadsheet-style column naming: 1 => A, 2 => B, ... 26 => Z,
     * 27 => AA, 28 => AB, ... so the generator never runs out of
     * blocks even for an unusually large "Number of Blocks".
     */
    private function letterForOrdinal(int $ordinal): string
    {
        $letters = '';

        while ($ordinal > 0) {
            $ordinal--;
            $letters = chr(65 + ($ordinal % 26)).$letters;
            $ordinal = intdiv($ordinal, 26);
        }

        return $letters;
    }
}