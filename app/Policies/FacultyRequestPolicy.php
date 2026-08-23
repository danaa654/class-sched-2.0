<?php

namespace App\Policies;

use App\Models\FacultyRequest;
use App\Models\User;
use App\Support\AccessScope;

class FacultyRequestPolicy
{
    /** Admin/Registrar review the whole queue; Dean/OIC/Assistant Dean see only requests within their own scope (query is still scoped, see FacultyRequest::scopeVisibleTo()). */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Administrator', 'Registrar', 'Dean', 'OIC', 'Assistant Dean']);
    }

    public function view(User $user, FacultyRequest $facultyRequest): bool
    {
        if (AccessScope::isUnrestricted($user)) {
            return true;
        }

        if ($facultyRequest->requested_by === $user->id) {
            return true;
        }

        if (AccessScope::isAssistantDean($user)) {
            return $facultyRequest->college_id === null;
        }

        return AccessScope::isCollegeScoped($user) && AccessScope::canAccessCollege($user, $facultyRequest->college_id);
    }

    /**
     * Only College-scoped roles submit requests — Admin/Registrar
     * have a direct path (FacultyController@store/@destroy) and never
     * need to request their own action.
     */
    public function create(User $user): bool
    {
        return AccessScope::isCollegeScoped($user) || AccessScope::isAssistantDean($user);
    }

    /** Only Admin/Registrar may approve or reject — this IS the safety gate the whole feature exists for. */
    public function review(User $user): bool
    {
        return AccessScope::isUnrestricted($user);
    }

    /**
     * A requester may cancel their own request while it is still
     * Pending. Admin/Registrar may also cancel/withdraw a Pending
     * request without deciding it (e.g. duplicate submission).
     */
    public function cancel(User $user, FacultyRequest $facultyRequest): bool
    {
        if ($facultyRequest->status !== 'Pending') {
            return false;
        }

        return AccessScope::isUnrestricted($user) || $facultyRequest->requested_by === $user->id;
    }
}