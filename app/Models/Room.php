<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
}