<?php

namespace App\Exceptions;

/**
 * Thrown from INSIDE a locked DB::transaction() (see
 * ScheduleConflictService::lockResources() and
 * SectionSubjectController::performScheduleAssignmentUpdate()) the
 * moment a conflict/validation error is found under lock, so the
 * transaction rolls back with no partial write, and the controller
 * can catch it outside the transaction and turn it back into the
 * exact same 422 { errors: {...} } response shape callers already
 * expect — concurrency-safety changes HOW the check happens, never
 * WHAT the response looks like to the frontend.
 */
class ScheduleConflictAbort extends \RuntimeException
{
    /**
     * @param  array<string, string>  $errors  Field-keyed error messages,
     *         same shape ScheduleConflictService::validate() returns.
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Schedule conflict: '.implode(' ', $errors));
    }
}