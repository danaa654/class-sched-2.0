<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Room Master record — a classroom, laboratory, or other facility
 * that can later be used during scheduling.
 *
 * This module only stores room information. Availability, schedule
 * assignment, and conflict checking are handled by later modules.
 */
class Room extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'room_code',
        'room_name',
        'building',
        'floor',
        'room_type',
        'room_category',
        'department_id',
        'college_id',
        'capacity',
        'status',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    /**
     * The Department this room "belongs to", if any. Used by the
     * Intelligent Room Recommendation Engine's Same College/Department
     * scoring criterion. Many rooms (gymnasium, shared lecture halls)
     * have no Department and are open to every Section.
     *
     * @return BelongsTo<Department, Room>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The College this room "belongs to", if any.
     *
     * @return BelongsTo<College, Room>
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * Raw recommendation rows for this Room (includes inactive ones).
     *
     * @return HasMany<RoomSubjectRecommendation>
     */
    public function subjectRecommendations(): HasMany
    {
        return $this->hasMany(RoomSubjectRecommendation::class);
    }

    /**
     * Subjects recommended (soft preference) for this Room — the
     * "Recommended Subjects" list shown on the Room Details page.
     * A soft preference only; never a hard scheduling constraint.
     * See RecommendationService::recommendRooms().
     *
     * @return BelongsToMany<Subject>
     */
    public function recommendedSubjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'room_subject_recommendations')
            ->wherePivot('active', true)
            ->withPivot(['id', 'active', 'created_by', 'created_at'])
            ->withTimestamps();
    }

    /**
     * RBAC query scope (spec Section 16-17): rooms are visible to
     * everyone (Dean/OIC need to see shared/eligible rooms outside
     * their College to schedule), but this scope is available for
     * views that must show only rooms a College may *administer* —
     * i.e. their own College's rooms plus unrestricted/shared ones.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Room>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Room>
     */
    public function scopeManageableBy($query, ?\App\Models\User $user)
    {
        if (\App\Support\AccessScope::isUnrestricted($user)) {
            return $query;
        }

        // Room administration is Admin/Registrar-only (see RoomPolicy),
        // so any other role gets zero manageable rows.
        return $query->whereRaw('1 = 0');
    }

    /**
     * Rooms a user may USE for scheduling: shared/general rooms plus
     * their own College's (Dean/OIC), or everything (Admin/Registrar/
     * Assistant Dean).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Room>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Room>
     */
    public function scopeUsableBy($query, ?\App\Models\User $user)
    {
        if (\App\Support\AccessScope::isUnrestricted($user) || \App\Support\AccessScope::isAssistantDean($user)) {
            return $query;
        }

        $collegeId = \App\Support\AccessScope::collegeId($user);

        return $query->where(function ($inner) use ($collegeId) {
            $inner->whereNull('college_id')->orWhere('college_id', $collegeId ?? -1);
        });
    }
}