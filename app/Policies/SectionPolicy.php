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