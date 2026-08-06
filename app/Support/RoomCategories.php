<?php

namespace App\Support;

/**
 * The single canonical list of Room Categories used across the system.
 *
 * Both `rooms.room_category` and `subjects.preferred_room_category`
 * are validated against this exact list, so a Subject's preference
 * can always be compared 1-to-1 against a Room's actual category in
 * RecommendationService::recommendRooms() — no fuzzy string matching,
 * no drift between the two dropdowns.
 */
final class RoomCategories
{
    /**
     * @var list<string>
     */
    public const LIST = [
        'Classroom',
        'Lecture Hall',
        'Computer Laboratory',
        'Networking Laboratory',
        'Chemistry Laboratory',
        'Physics Laboratory',
        'Science Laboratory',
        'Gymnasium',
        'Covered Court / Open Area',
        'Multipurpose Hall',
    ];
}