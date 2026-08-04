<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'curriculum_id',
        'subject_id',
        'year_level',
        'semester',
        'prerequisite_subject_id',
        'remarks',
    ];

    /**
     * The Curriculum this item belongs to.
     *
     * @return BelongsTo<Curriculum, CurriculumItem>
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /**
     * The master Subject placed by this item. The Curriculum only ever
     * references this Subject — it never owns its own copy of it.
     *
     * @return BelongsTo<Subject, CurriculumItem>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * The master Subject that must be taken before this one, if any.
     *
     * @return BelongsTo<Subject, CurriculumItem>
     */
    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'prerequisite_subject_id');
    }
}