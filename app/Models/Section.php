<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Section — a group of students under a Major, Curriculum, and Year
 * Level that will receive a class schedule.
 *
 * This module only stores the section itself. Subjects, faculty,
 * rooms, and schedules are assigned by later modules.
 */
class Section extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'section_code',
        'section_name',
        'major_id',
        'curriculum_id',
        'academic_year',
        'year_level',
        'estimated_students',
        'status',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimated_students' => 'integer',
        ];
    }

    /**
     * The Major this Section belongs to.
     *
     * @return BelongsTo<Major, Section>
     */
    public function major(): BelongsTo
    {
        return $this->belongsTo(Major::class);
    }

    /**
     * The Curriculum this Section follows. Must belong to the same
     * Major (enforced in StoreSectionRequest/UpdateSectionRequest).
     *
     * @return BelongsTo<Curriculum, Section>
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /**
     * The pivot placements (Section Subjects) for this Section. Prefer
     * this over `subjects()` when you need the Source/Remarks pivot
     * data directly (e.g. listing the Section's subject table).
     *
     * @return HasMany<SectionSubject>
     */
    public function sectionSubjects(): HasMany
    {
        return $this->hasMany(SectionSubject::class);
    }

    /**
     * The master Subjects assigned to this Section. This is the
     * Section's subject list — NOT the schedule. No faculty, room, or
     * time is attached here.
     *
     * @return BelongsToMany<Subject>
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'section_subjects')
            ->withPivot(['id', 'source', 'remarks'])
            ->withTimestamps();
    }
}