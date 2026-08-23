<?php

namespace App\Policies;

use App\Models\FacultyLoadRequest;
use App\Models\User;
use App\Support\AccessScope;

class FacultyLoadRequestPolicy
{
    /** Admin/Registrar review the queue; Dean/OIC/Assistant Dean see only their own submissions (scoped in the controller query). */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Administrator', 'Registrar', 'Dean', 'OIC', 'Assistant Dean']);
    }

    public function view(User $user, FacultyLoadRequest $loadRequest): bool
    {
        if (AccessScope::isUnrestricted($user)) {
            return true;
        }

        return $loadRequest->requested_by === $user->id
            || (AccessScope::isCollegeScoped($user) && AccessScope::canAccessCollege($user, $loadRequest->faculty->college_id))
            || (AccessScope::isAssistantDean($user) && $loadRequest->faculty->college_id === null);
    }

    /**
     * Anyone who can already view/edit this Faculty may request a load
     * change for them — including Admin/Registrar, even though they
     * could just edit the field directly; this keeps a single
     * consistent entry point and audit trail.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['Administrator', 'Registrar', 'Dean', 'OIC', 'Assistant Dean']);
    }

    /** Only Admin/Registrar may approve or deny — this IS the safety gate the whole feature exists for. */
    public function review(User $user): bool
    {
        return AccessScope::isUnrestricted($user);
    }

    /**
     * Only Admin/Registrar may delete, and only requests that have
     * already been decided (Approved/Denied). A Pending request must
     * go through review() — Approve or Deny — not be silently
     * removed, since that would leave the Dean/OIC who submitted it
     * with no notification and no record of what happened.
     */
    public function delete(User $user, FacultyLoadRequest $loadRequest): bool
    {
        return AccessScope::isUnrestricted($user) && $loadRequest->status !== 'Pending';
    }
}