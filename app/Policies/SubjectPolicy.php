<?php

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;
use App\Support\AccessScope;

class SubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Administrator', 'Registrar', 'Assistant Dean', 'Dean', 'OIC']);
    }

    public function view(User $user, Subject $subject): bool
    {
        // Every authorized role may VIEW any subject (Dean/OIC need to
        // see GenEd/Minor subjects to schedule their own sections) —
        // it's modifying the definition that is scoped.
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return AccessScope::isUnrestricted($user) || AccessScope::isAssistantDean($user) || AccessScope::isCollegeScoped($user);
    }

    /**
     * Whether the user may create a subject of the given category
     * (and, for Major subjects, owned by the given Major/College).
     */
    public function createOfCategory(User $user, string $category, ?int $ownerCollegeId = null): bool
    {
        if (AccessScope::isUnrestricted($user)) {
            return true;
        }

        if (AccessScope::isSharedCategory($category)) {
            return AccessScope::isAssistantDean($user);
        }

        return AccessScope::isCollegeScoped($user) && AccessScope::canAccessCollege($user, $ownerCollegeId);
    }

    public function update(User $user, Subject $subject): bool
    {
        return $this->canModify($user, $subject);
    }

    public function delete(User $user, Subject $subject): bool
    {
        return $this->canModify($user, $subject);
    }

    private function canModify(User $user, Subject $subject): bool
    {
        if (AccessScope::isUnrestricted($user)) {
            return true;
        }

        $category = (string) $subject->category;
        $ownerCollegeId = $subject->college_id ?? $subject->major?->department?->college_id;

        if (AccessScope::isSharedCategory($category)) {
            // GenEd/Minor definitions belong to the Assistant Dean;
            // Dean/OIC may use but never modify them (spec §13).
            return AccessScope::isAssistantDean($user);
        }

        return AccessScope::isCollegeScoped($user) && AccessScope::canAccessCollege($user, $ownerCollegeId);
    }
}