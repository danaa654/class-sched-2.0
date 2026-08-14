<?php

namespace App\Policies;

use App\Models\User;
use App\Support\AccessScope;

class UserPolicy
{
    /** Only Administrator may see the Users management page at all (spec §3, §26). */
    public function viewAny(User $user): bool
    {
        return AccessScope::isAdministrator($user);
    }

    public function view(User $user, User $target): bool
    {
        return AccessScope::isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return AccessScope::isAdministrator($user);
    }

    public function update(User $user, User $target): bool
    {
        return AccessScope::isAdministrator($user);
    }

    public function delete(User $user, User $target): bool
    {
        // Administrators may never deactivate/delete their own account
        // through this flow — avoids locking the system out.
        return AccessScope::isAdministrator($user) && $user->is($target) === false;
    }

    public function changeRole(User $user, User $target): bool
    {
        return AccessScope::isAdministrator($user) && $user->is($target) === false;
    }

    public function changeCollegeScope(User $user, User $target): bool
    {
        return AccessScope::isAdministrator($user) && $user->is($target) === false;
    }
}