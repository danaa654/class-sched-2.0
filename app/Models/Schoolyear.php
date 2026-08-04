<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolYear extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'start_year',
        'end_year',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_year' => 'integer',
        'end_year' => 'integer',
    ];

    /**
     * The Academic Terms built on this School Year.
     */
    public function academicTerms(): HasMany
    {
        return $this->hasMany(AcademicTerm::class);
    }

    /**
     * Enforce the "only one Active School Year at a time" rule.
     *
     * Whenever a School Year is saved with status Active, every other
     * School Year is flipped to Inactive. Runs on `saved` (after the
     * triggering record is persisted) so it doesn't clobber its own row,
     * and is scoped to `withTrashed()` so a soft-deleted School Year left
     * behind as Active doesn't stick around as a second "active" record.
     */
    protected static function booted(): void
    {
        static::saved(function (SchoolYear $schoolYear) {
            if ($schoolYear->status === 'Active') {
                static::withTrashed()
                    ->where('id', '!=', $schoolYear->id)
                    ->where('status', 'Active')
                    ->update(['status' => 'Inactive']);
            }
        });
    }
}