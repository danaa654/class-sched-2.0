<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Major extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'code',
        'name',
        'short_name',
        'years',
        'description',
        'status',
    ];

    /**
     * The department this major belongs to.
     *
     * @return BelongsTo<Department, Major>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The college this major belongs to, through its department.
     *
     * Convenience helper only — not a true Eloquent relation, since
     * College doesn't need a HasManyThrough to reach Majors elsewhere.
     * Prefer eager-loading `department.college` for queries; use this
     * when you already have the model loaded and just need the college.
     */
    public function college(): ?College
    {
        return $this->department?->college;
    }

    /**
     * The Curriculums (academic plans) built for this Major.
     * A Major may have several — one per Effective Year.
     *
     * @return HasMany<Curriculum>
     */
    public function curriculums(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }
}