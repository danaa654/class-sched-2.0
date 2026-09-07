<?php

namespace App\Services;

use App\Models\SectionSubject;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Support\Collection;

/**
 * SIGN-OFF SIGNATORIES — shared between the printed Schedule by
 * Faculty report (ReportsService/print.blade.php) and the emailed
 * Faculty Teaching Schedule PDF (FacultyScheduleMail/
 * pdf-faculty-schedule.blade.php), so "who's listed as Dean/Approver"
 * can never drift between the two documents. Both only ever call
 * these two methods — neither re-implements the College/role
 * resolution itself.
 */
class SignoffService
{
    /**
     * "Noted by" signatories — the Dean (or OIC, standing in for one)
     * of every distinct College among the given SectionSubjects' own
     * Sections (via Section->major->department->college, the same
     * chain NotificationService::recipientsFor() resolves a College
     * from). A faculty member teaching across multiple Colleges (e.g.
     * a CCS faculty also carrying a GenEd load under CTE) gets every
     * one of those Colleges' Dean/OIC listed, not just their own home
     * College. A College with neither role currently assigned is
     * silently skipped — callers already render gracefully with zero
     * signatories, no placeholder needed.
     *
     * @param  Collection<int, SectionSubject>  $sectionSubjects
     * @return list<array{college: string, name: string, role: string}>
     */
    public function deansForColleges(Collection $sectionSubjects): array
    {
        $colleges = $sectionSubjects
            ->map(fn (SectionSubject $ss) => $ss->section?->major?->department?->college)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        return $colleges
            ->flatMap(function ($college) {
                return User::query()
                    ->role(AccessScope::COLLEGE_SCOPED_ROLES)
                    ->where('college_id', $college->id)
                    ->get()
                    ->sortBy(fn (User $user) => $user->hasRole('Dean') ? 0 : 1)
                    ->map(fn (User $user) => [
                        'college' => $college->name,
                        'name' => $user->full_name ?? $user->name,
                        'role' => $user->hasRole('Dean') ? 'Dean' : 'OIC',
                    ]);
            })
            ->values()
            ->all();
    }

    /**
     * "Approved by" signatories — every Administrator and Registrar
     * currently in the system (institution-wide roles, per
     * AccessScope::UNRESTRICTED_ROLES — unlike deansForColleges()
     * above, this is never scoped to a College, since Registrar/
     * Administrator oversee scheduling across the whole institution).
     * Ordered Administrator-then-Registrar to match that same
     * constant's order everywhere else it's used.
     *
     * @return list<array{name: string, role: string}>
     */
    public function approvers(): array
    {
        return User::query()
            ->role(AccessScope::UNRESTRICTED_ROLES)
            ->get()
            ->sortBy(fn (User $user) => $user->hasRole('Administrator') ? 0 : 1)
            ->map(fn (User $user) => [
                'name' => $user->full_name ?? $user->name,
                'role' => $user->hasRole('Administrator') ? 'Administrator' : 'Registrar',
            ])
            ->values()
            ->all();
    }
}