<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;
use App\Support\AccessScope;

class RoomPolicy
{
    /** Everyone with a Scheduling-side role may browse/use Rooms for scheduling. */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['Administrator', 'Registrar', 'Assistant Dean', 'Dean', 'OIC']);
    }

    public function view(User $user, Room $room): bool
    {
        // Viewing (for scheduling purposes) is allowed institution-wide
        // — Assistant Dean and Dean/OIC need to see shared/eligible
        // rooms outside their own College to schedule GenEd/Minor or
        // cross-College-shared classes. It's *administering* a Room
        // (capacity/type/College restriction) that is scoped, below.
        return $this->viewAny($user);
    }

    /** Room administration (create/edit restrictions, capacity, type, ownership) is Admin/Registrar only by default. */
    public function create(User $user): bool
    {
        return AccessScope::isUnrestricted($user);
    }

    public function update(User $user, Room $room): bool
    {
        return AccessScope::isUnrestricted($user);
    }

    public function delete(User $user, Room $room): bool
    {
        return AccessScope::isUnrestricted($user);
    }

    /**
     * Whether the user may use this Room when scheduling (subject to
     * the existing Room Recommendation override rules, which remain
     * untouched — this only governs who may pick from eligible rooms).
     */
    public function use(User $user, Room $room): bool
    {
        if (AccessScope::isUnrestricted($user) || AccessScope::isAssistantDean($user)) {
            return true;
        }

        // College-restricted rooms: only usable by that College's
        // Dean/OIC (or, for shared/general rooms, college_id is null).
        if (AccessScope::isCollegeScoped($user)) {
            return $room->college_id === null || AccessScope::canAccessCollege($user, $room->college_id);
        }

        return false;
    }
}