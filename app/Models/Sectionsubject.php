<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The placement of one master Subject inside one Section's subject
 * list. This is the pivot record for Section <-> Subject, carrying
 * where the placement came from (Source) plus the (initially empty)
 * schedule slot for that subject — Capacity, Faculty, Room, Days,
 * Start/End Time, and Status.
 *
 * A newly-added subject always starts with every schedule field
 * empty and Status = 'Draft'. Faculty/Room/Time are never assigned
 * automatically — that happens later, in the scheduling engine.
 */
class SectionSubject extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'section_id',
        'subject_id',
        'source',
        'capacity',
        'faculty_id',
        'room_id',
        'days',
        'start_time',
        'end_time',
        'status',
        'remarks',
        'edp_code',
        'is_auto_generated',
        'auto_generated_meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_auto_generated' => 'boolean',
            'auto_generated_meta' => 'array',
        ];
    }

    /**
     * The Section this placement belongs to.
     *
     * @return BelongsTo<Section, SectionSubject>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * The master Subject placed into the Section.
     *
     * @return BelongsTo<Subject, SectionSubject>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * The Faculty assigned to teach this subject for this section.
     * Null until assigned by the scheduling engine.
     *
     * @return BelongsTo<Faculty, SectionSubject>
     */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    /**
     * The Room assigned for this subject's meetings. Null until
     * assigned by the scheduling engine.
     *
     * @return BelongsTo<Room, SectionSubject>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}