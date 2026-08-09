<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A soft preference: "Subject X is recommended for Room Y."
 *
 * Configured on the Room Details page (Room is the source of truth).
 * Consumed by RecommendationService::recommendRooms() and
 * AutoScheduleService as a scoring bonus only — it never overrides a
 * hard constraint (capacity, room type, conflicts, availability) and
 * never locks a Subject into a Room. See Room::recommendedSubjects()
 * and Subject::recommendedRooms().
 */
class RoomSubjectRecommendation extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'room_id',
        'subject_id',
        'active',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Room, RoomSubjectRecommendation>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<Subject, RoomSubjectRecommendation>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<User, RoomSubjectRecommendation>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}