<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Curriculum extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'curriculums';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'major_id',
        'code',
        'name',
        'start_year',
        'end_year',
        'status',
        'allow_new_students',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_year' => 'integer',
            'end_year' => 'integer',
            'allow_new_students' => 'boolean',
        ];
    }

    /**
     * The Major (academic plan owner) this Curriculum belongs to.
     *
     * @return BelongsTo<Major, Curriculum>
     */
    public function major(): BelongsTo
    {
        return $this->belongsTo(Major::class);
    }

    /**
     * The Subjects mapped into this Curriculum, by Year Level / Semester.
     * This is the Curriculum's structure — it never duplicates a Subject
     * record, only references it via CurriculumItem.
     *
     * @return HasMany<CurriculumItem>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CurriculumItem::class);
    }
}