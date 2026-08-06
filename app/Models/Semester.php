<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Semester extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The three Semesters the Academic Term form's Semester dropdown
     * offers, in display order. Hardcoded rather than pulled from the
     * database so the dropdown always works with zero setup — no
     * seeding required. AcademicTermController@resolveSemester finds
     * or creates the matching row (short_name/display_order derived
     * below) the first time each one is actually used.
     *
     * @var list<string>
     */
    public const NAMES = ['1st Semester', '2nd Semester', 'Summer'];

    /**
     * short_name / display_order used when a Semester in NAMES is
     * created for the first time.
     */
    public static function defaultsFor(string $name): array
    {
        return match ($name) {
            '1st Semester' => ['short_name' => '1st Sem', 'display_order' => 1],
            '2nd Semester' => ['short_name' => '2nd Sem', 'display_order' => 2],
            'Summer' => ['short_name' => 'Summer', 'display_order' => 3],
            default => ['short_name' => $name, 'display_order' => 99],
        };
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'short_name',
        'display_order',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'display_order' => 'integer',
    ];

    /**
     * The Academic Terms built on this Semester.
     */
    public function academicTerms(): HasMany
    {
        return $this->hasMany(AcademicTerm::class);
    }
}