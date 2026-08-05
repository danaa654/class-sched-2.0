<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The placement of one master Subject inside one Section's subject
 * list. This is the pivot record for Section <-> Subject, carrying
 * where the placement came from (Source) and optional Remarks.
 *
 * This is NOT the schedule — no faculty, room, or time lives here.
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
        'remarks',
    ];

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
}