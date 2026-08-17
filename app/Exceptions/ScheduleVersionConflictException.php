<?php

namespace App\Exceptions;

/**
 * CONCURRENCY HARDENING — Optimistic Concurrency Control.
 *
 * Thrown from INSIDE a locked DB::transaction() (see
 * ScheduleConflictService::checkSectionVersion()) the moment a
 * caller-submitted `expected_schedule_version` no longer matches the
 * Section's CURRENT `schedule_version`, read under a row lock. This
 * means another request already committed a change to this Section's
 * schedule since the caller's data was loaded/generated.
 *
 * Rolls the transaction back with no partial write — never a silent
 * overwrite — and is caught by the controller to return HTTP 409 with
 * code SCHEDULE_VERSION_CONFLICT and the current version, so the
 * frontend can prompt the user to refresh and retry.
 */
class ScheduleVersionConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion, ?int $submittedVersion = null)
    {
        parent::__construct(
            "Schedule version conflict: submitted version {$submittedVersion} does not match current version {$currentVersion}."
        );
    }
}