<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'subject_code',
        'subject_title',
        'major_id',
        'category',
        'units',
        'lecture_hours',
        'laboratory_hours',
        'is_active',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'lecture_hours' => 'integer',
            'laboratory_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The Major this subject belongs to. Null for General Education
     * subjects, which are shared across all Majors.
     *
     * @return BelongsTo<Major, Subject>
     */
    public function major(): BelongsTo
    {
        return $this->belongsTo(Major::class);
    }

    /**
     * Curriculum placements that reference this Subject as the subject
     * itself (not as a prerequisite). Used to block deletion of a
     * Subject that's already mapped into a Curriculum.
     *
     * @return HasMany<CurriculumItem>
     */
    public function curriculumItems(): HasMany
    {
        return $this->hasMany(CurriculumItem::class);
    }

    /**
     * Faculty members qualified to teach this subject.
     *
     * @return BelongsToMany<Faculty>
     */
    public function faculty(): BelongsToMany
    {
        return $this->belongsToMany(Faculty::class, 'faculty_subject')->withTimestamps();
    }
}