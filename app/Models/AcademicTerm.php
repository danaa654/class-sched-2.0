<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An Academic Term is a School Year + Semester combination.
 *
 * The Global Scheduling Settings the Auto Schedule AI reads (Class
 * Start/End Time, Time Interval, Available Class Days, Lunch Break)
 * live on the School Year this term belongs to — see
 * SchoolYear::active() and schoolYear(). They are intentionally not
 * duplicated here.
 */
class AcademicTerm extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'school_year_id',
        'semester_id',
        'status',
        'remarks',
    ];

    /**
     * The School Year this Academic Term belongs to.
     */
    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    /**
     * The Semester this Academic Term belongs to.
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * The single Active Academic Term, if one exists.
     */
    public static function active(): ?self
    {
        return static::query()->where('status', 'Active')->first();
    }

    /**
     * Enforce the "only one Active Academic Term at a time" rule.
     *
     * Mirrors SchoolYear::booted(): when an Academic Term is saved as
     * Active, every other Academic Term is flipped to Inactive. Runs on
     * `saved` so it doesn't clobber its own row, and is scoped to
     * `withTrashed()` so a soft-deleted term left behind as Active
     * doesn't stick around as a second "active" record.
     */
    protected static function booted(): void
    {
        static::saved(function (AcademicTerm $academicTerm) {
            if ($academicTerm->status === 'Active') {
                static::withTrashed()
                    ->where('id', '!=', $academicTerm->id)
                    ->where('status', 'Active')
                    ->update(['status' => 'Inactive']);
            }
        });
    }
}