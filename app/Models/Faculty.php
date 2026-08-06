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
        'max_teaching_units',
        'status',
        'email',
        'contact_number',
        'remarks',
    ];

    /**
     * `faculty_category` is derived (see getFacultyCategoryAttribute()
     * below) rather than a real column, so it must be explicitly
     * appended to appear in JSON responses the same way the old stored
     * column did.
     *
     * @var list<string>
     */
    protected $appends = ['faculty_category'];

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
     * Weekly availability windows for this faculty member.
     *
     * At most one record per day of week. The scheduling engine's
     * Genetic Algorithm reads this relationship to ensure it never
     * assigns a faculty member outside their declared available hours.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<FacultyAvailability>
     */
    public function availabilities(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FacultyAvailability::class)->orderByRaw(
            "FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')"
        );
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

    /**
     * Derived from `college_id` rather than stored, so this can never
     * drift out of sync with it: no College means General Education
     * Faculty (GenEd/Minor — English, Math, Filipino, NSTP, PE, etc.),
     * otherwise the faculty member belongs to a department (College).
     */
    public function getFacultyCategoryAttribute(): string
    {
        return $this->college_id === null
            ? 'General Education Faculty'
            : 'Department Faculty';
    }
}