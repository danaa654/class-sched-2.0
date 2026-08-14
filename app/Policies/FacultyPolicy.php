<?php

namespace App\Policies;

use App\Models\Faculty;
use App\Models\User;
use App\Support\AccessScope;

class FacultyPolicy
{
    /** Everyone with a Scheduling-side role may see the roster (query is still scoped). */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Administrator', 'Registrar', 'Assistant Dean', 'Dean', 'OIC']);
    }

    public function view(User $user, Faculty $faculty): bool
    {
        return $this->canAccess($user, $faculty);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['Administrator', 'Registrar', 'Dean', 'OIC']);
    }

    /**
     * A College-scoped Dean/OIC creating a Faculty record may only
     * assign it to their own College (or leave it a GenEd/Minor
     * faculty with no College — but that is the Assistant Dean's
     * lane to manage further; creation of the base record is allowed
     * so Deans can rostering their own people).
     */
    public function createForCollege(User $user, ?int $collegeId): bool
    {
        if (AccessScope::isUnrestricted($user)) {
            return true;
        }

        if (AccessScope::isCollegeScoped($user)) {
            return AccessScope::canAccessCollege($user, $collegeId);
        }

        return false;
    }

    public function update(User $user, Faculty $faculty): bool
    {
        return $this->canAccess($user, $faculty);
    }

    public function delete(User $user, Faculty $faculty): bool
    {
        return AccessScope::isUnrestricted($user)
            || (AccessScope::isCollegeScoped($user) && AccessScope::canAccessCollege($user, $faculty->college_id));
    }

    /**
     * Whether the user may reassign this Faculty member to a
     * different College. Per spec, Dean/OIC (and Assistant Dean) may
     * NEVER move a faculty between Colleges — only Admin/Registrar.
     */
    public function reassignCollege(User $user): bool
    {
        return AccessScope::isUnrestricted($user);
    }

    /**
     * Whether the user may manage a specific teaching qualification
     * entry, based on whether that qualification is a Major subject
     * (College-owned, Dean/OIC's lane) or GenEd/Minor (Assistant
     * Dean's lane), regardless of which College the faculty belongs to.
     */
    public function manageQualification(User $user, Faculty $faculty, string $subjectCategory): bool
    {
        if (AccessScope::isUnrestricted($user)) {
            return true;
        }

        if (AccessScope::isSharedCategory($subjectCategory)) {
            return AccessScope::isAssistantDean($user);
        }

        return AccessScope::isCollegeScoped($user) && AccessScope::canAccessCollege($user, $faculty->college_id);
    }

    private function canAccess(User $user, Faculty $faculty): bool
    {
        if (AccessScope::isUnrestricted($user)) {
            return true;
        }

        // Assistant Dean's lane is GenEd/Minor faculty, represented in
        // this codebase as a Faculty record with no college_id (see
        // FacultyController's "General Education Faculty" filter).
        if (AccessScope::isAssistantDean($user)) {
            return $faculty->college_id === null;
        }

        if (AccessScope::isCollegeScoped($user)) {
            return AccessScope::canAccessCollege($user, $faculty->college_id);
        }

        return false;
    }
}