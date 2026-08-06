<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}