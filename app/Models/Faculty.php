<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Faculty Master record.
 *
 * IMPORTANT: Faculty members are NOT system users — they never log in.
 * This model exists purely so the registrar/admin can maintain a roster
 * of people available to be assigned teaching loads during scheduling.
 * Subject/schedule/room/section assignment is handled by later modules;
 * this model only stores faculty identity and eligibility information.
 */
class Faculty extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'faculty_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'employment_type',
        'college_id',
        'department_id',
        'specialization',
        'max_teaching_units',
        'status',
        'email',
        'contact_number',
        'remarks',
    ];

    /**
     * The college this faculty member belongs to.
     *
     * @return BelongsTo<College, Faculty>
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * The department this faculty member belongs to.
     *
     * @return BelongsTo<Department, Faculty>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Subjects this faculty member is qualified to teach.
     *
     * Teaching qualification is a pure many-to-many link (via
     * `faculty_subject`) — it carries no extra data of its own. The
     * scheduling engine's Genetic Algorithm reads this relationship to
     * ensure it never assigns a faculty member to a subject they are
     * not qualified for.
     *
     * @return BelongsToMany<Subject>
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'faculty_subject')->withTimestamps();
    }

    /**
     * Full display name, formatted "Last, First Middle Suffix".
     */
    public function getFullNameAttribute(): string
    {
        $middleInitial = $this->middle_name ? ' '.mb_substr($this->middle_name, 0, 1).'.' : '';
        $suffix = $this->suffix ? ' '.$this->suffix : '';

        return "{$this->last_name}, {$this->first_name}{$middleInitial}{$suffix}";
    }
}