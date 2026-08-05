<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single day's availability window for a Faculty member.
 *
 * One record per (faculty_id, day_of_week) — enforced by a unique
 * constraint. This table is the source of truth the scheduling
 * engine's Genetic Algorithm must consult: a faculty member must
 * NEVER be assigned a class outside the hours declared here (or on
 * a day where is_available is false).
 */
class FacultyAvailability extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'faculty_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_available',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_available' => 'boolean',
    ];

    /**
     * The faculty member this availability window belongs to.
     *
     * @return BelongsTo<Faculty, FacultyAvailability>
     */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }
}