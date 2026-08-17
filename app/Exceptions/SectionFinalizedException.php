<?php

namespace App\Exceptions;

use App\Models\Section;

/**
 * SECTION-LEVEL SCHEDULE FINALIZATION.
 *
 * Thrown from INSIDE a locked DB::transaction() (see
 * ScheduleConflictService::lockResources()) the moment a write
 * targets a Section whose `is_finalized` flag is set. This is the
 * single enforcement gate for the feature: every write path that
 * modifies a Section's schedule (manual cell edit, Room Grid move,
 * Save Schedule batch submit, Auto Generate) goes through
 * lockResources() first, so none of them can bypass this check.
 *
 * Distinct from ScheduleVersionConflictException — that one means
 * "someone else just changed this, reload and retry"; this one means
 * "this Section's schedule is locked and editing isn't allowed at
 * all until a Registrar/Admin unlocks it." Callers should surface a
 * clearly different message so users don't confuse the two.
 */
class SectionFinalizedException extends \RuntimeException
{
    public function __construct(public readonly Section $section)
    {
        parent::__construct(
            "Section [{$section->id}] '{$section->section_code}' schedule is finalized and cannot be edited."
        );
    }
}