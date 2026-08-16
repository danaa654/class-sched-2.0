<?php

namespace App\Policies;

use App\Models\Section;
use App\Models\User;
use App\Support\AccessScope;

class SectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Administrator', 'Registrar', 'Assistant Dean', 'Dean', 'OIC']);
    }

    public function view(User $user, Section $section): bool
    {
        return $this->canAccess($user, $section);
    }

    /** Full Section CRUD (create/rename/delete/change program) is Registrar/Admin/College-owner only — never Assistant Dean (spec §8). */
    public function create(User $user): bool
    {
        return AccessScope::isUnrestricted($user) || AccessScope::isCollegeScoped($user);
    }

    public function createForCollege(User $user, ?int $collegeId): bool
    {
        if (AccessScope::isUnrestricted($user)) {
            return true;
        }

        return AccessScope::isCollegeScoped($user) && AccessScope::canAccessCollege($user, $collegeId);
    }

    public function update(User $user, Section $section): bool
    {
        return $this->canAccess($user, $section) && ! AccessScope::isAssistantDean($user);
    }

    public function delete(User $user, Section $section): bool
    {
        return AccessScope::isUnrestricted($user)
            || (AccessScope::isCollegeScoped($user) && AccessScope::canAccessCollege($user, $section->major?->college()?->id));
    }

    /**
     * Assistant Dean and Dean/OIC may manage GenEd/Minor SUBJECT
     * ASSIGNMENTS and scheduling for a section they can see, without
     * full Section CRUD rights (spec §8, §15).
     */
    public function manageScheduling(User $user, Section $section): bool
    {
        return $this->canAccess($user, $section);
    }

    /**
     * Room Grid drag/move authorization for one SPECIFIC schedule
     * assignment.
     *
     * Authorization model: CURRENT SECTION = UI CONTEXT ONLY,
     * SCHEDULING SCOPE = the actual permission. Whether a block is
     * movable depends on whether the authenticated user has
     * scheduling authority (manageScheduling/canAccess — their
     * authorized College/Department) over the SCHEDULE'S OWN Section
     * — never on whether that Section happens to match whichever
     * Section is currently selected in the UI. A CCS-scoped user can
     * move any BSIT-* Section's block from any other BSIT-* Section's
     * Room Grid without switching sections first.
     *
     * $currentSectionId is accepted only to tell the caller whether
     * this is a same-section move (no confirmation needed) or a
     * cross-section move within scope (needs the "Move Schedule
     * Assignment?" confirmation) — it is never itself part of the
     * authorization decision. A schedule outside the user's
     * authorized scope stays locked regardless of which section is
     * currently selected.
     */
    public function moveScheduleAssignment(User $user, Section $assignmentSection): bool
    {
        return $this->manageScheduling($user, $assignmentSection);
    }

    private function canAccess(User $user, Section $section): bool
    {
        if (AccessScope::isUnrestricted($user) || AccessScope::isAssistantDean($user)) {
            return true;
        }

        if (AccessScope::isCollegeScoped($user)) {
            return AccessScope::canAccessCollege($user, $section->major?->college()?->id);
        }

        return false;
    }
}