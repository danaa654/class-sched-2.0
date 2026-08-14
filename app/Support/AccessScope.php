<?php

namespace App\Support;

use App\Models\User;

/**
 * Central ROLE + SCOPE resolver for Classly's authorization model.
 *
 * Authorization here is always ROLE + SCOPE + RESOURCE + ACTION, never a
 * flat boolean. This class is the single source of truth for what a
 * user's SCOPE is, so Policies, Controllers, and query scoping never
 * duplicate (and risk disagreeing on) this logic.
 *
 * Roles come from Spatie Permission (see database/seeders/RoleSeeder.php).
 * College scope comes from users.college_id (Dean/OIC only).
 */
class AccessScope
{
    /** Roles with unrestricted access to every College's resources. */
    public const UNRESTRICTED_ROLES = ['Administrator', 'Registrar'];

    /** Roles whose scope is College-bound (Dean/OIC). */
    public const COLLEGE_SCOPED_ROLES = ['Dean', 'OIC'];

    /** The role limited to GenEd/Minor resources across all Colleges. */
    public const ASSISTANT_DEAN_ROLE = 'Assistant Dean';

    /** Only Administrator may manage user accounts, roles, and scope. */
    public const USER_MANAGEMENT_ROLES = ['Administrator'];

    public static function isAdministrator(?User $user): bool
    {
        return (bool) $user?->hasRole('Administrator');
    }

    /**
     * True for roles that see every College's data (Admin/Registrar).
     * Does NOT include Assistant Dean, whose "all Colleges" access is
     * limited to GenEd/Minor resources only — see isAssistantDean().
     */
    public static function isUnrestricted(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(self::UNRESTRICTED_ROLES);
    }

    public static function isAssistantDean(?User $user): bool
    {
        return (bool) $user?->hasRole(self::ASSISTANT_DEAN_ROLE);
    }

    public static function isCollegeScoped(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(self::COLLEGE_SCOPED_ROLES);
    }

    /**
     * The College id a Dean/OIC is restricted to, or null if the user
     * is not College-scoped (or unrestricted / Assistant Dean).
     */
    public static function collegeId(?User $user): ?int
    {
        if (! self::isCollegeScoped($user)) {
            return null;
        }

        return $user?->college_id;
    }

    /**
     * True if a College-scoped Dean/OIC has no College assigned yet.
     * Per spec: this must NEVER be treated as unrestricted access.
     */
    public static function hasNoAssignedCollege(?User $user): bool
    {
        return self::isCollegeScoped($user) && ! $user?->college_id;
    }

    /**
     * Whether $collegeId is within the user's authorized scope.
     * Admin/Registrar: always true. Assistant Dean: true only for
     * GenEd/Minor-resource checks handled separately (see
     * canManageSubjectCategory). Dean/OIC: only their own College.
     */
    public static function canAccessCollege(?User $user, ?int $collegeId): bool
    {
        if (self::isUnrestricted($user)) {
            return true;
        }

        if (self::isCollegeScoped($user)) {
            return ! self::hasNoAssignedCollege($user) && $user->college_id === $collegeId;
        }

        return false;
    }

    /**
     * Whether the user may manage a Subject/Faculty resource of the
     * given "category" classification. $category is either 'Major'
     * (College-owned) or 'General Education' / 'Minor' (institution-
     * wide, shared, Assistant Dean's responsibility).
     */
    public static function canManageByCategory(?User $user, string $category, ?int $ownerCollegeId): bool
    {
        if (self::isUnrestricted($user)) {
            return true;
        }

        $isShared = self::isSharedCategory($category);

        if (self::isAssistantDean($user)) {
            return $isShared;
        }

        if (self::isCollegeScoped($user)) {
            // Dean/OIC may VIEW/USE shared GenEd/Minor resources for
            // scheduling their own sections, but the institution-wide
            // definition itself belongs to the Assistant Dean — callers
            // that need "can edit the definition" should pass false
            // here and rely on the isShared branch above for write
            // actions, or use canModifySharedDefinition().
            return ! $isShared && self::canAccessCollege($user, $ownerCollegeId);
        }

        return false;
    }

    public static function isSharedCategory(?string $category): bool
    {
        return in_array($category, ['General Education', 'Minor'], true);
    }

    /**
     * Dean/OIC may VIEW and USE (assign to their own sections) a
     * shared GenEd/Minor resource, but only Admin/Registrar/Assistant
     * Dean may modify the institution-wide definition itself.
     */
    public static function canModifySharedDefinition(?User $user): bool
    {
        return self::isUnrestricted($user) || self::isAssistantDean($user);
    }

    /**
     * The list of College ids a user's queries should be restricted
     * to, or null to mean "no restriction" (Admin/Registrar/Assistant
     * Dean-for-shared-resources). Dean/OIC get a single-id array (or
     * an impossible id if they have no College assigned, so their
     * queries return zero rows rather than leaking data).
     *
     * @return array<int>|null
     */
    public static function visibleCollegeIds(?User $user): ?array
    {
        if (self::isUnrestricted($user)) {
            return null;
        }

        if (self::isCollegeScoped($user)) {
            return self::hasNoAssignedCollege($user) ? [-1] : [$user->college_id];
        }

        // Assistant Dean has no College restriction for GenEd/Minor
        // resources — callers should additionally filter by category.
        if (self::isAssistantDean($user)) {
            return null;
        }

        return [-1];
    }
}