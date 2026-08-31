<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The placement of one master Subject inside one Section's subject
 * list. This is the pivot record for Section <-> Subject, carrying
 * where the placement came from (Source) plus the (initially empty)
 * schedule slot for that subject — Capacity, Faculty, Room, Days,
 * Start/End Time, and Status.
 *
 * A newly-added subject always starts with every schedule field
 * empty and Status = 'Draft'. Faculty/Room/Time are never assigned
 * automatically — that happens later, in the scheduling engine.
 */
class SectionSubject extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'section_id',
        'subject_id',
        'source',
        'capacity',
        'capacity_confirmed',
        'faculty_id',
        'room_id',
        'room_is_manual_override',
        'days',
        'start_time',
        'end_time',
        'hours_confirmed',
        'room_type_confirmed',
        'room_college_confirmed',
        'faculty_mismatch_confirmed',
        'status',
        'remarks',
        'edp_code',
        'is_auto_generated',
        'is_manually_modified',
        'auto_generated_meta',
        'is_merged',
        'merged_into_section_subject_id',
        'merge_recommendation',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'capacity_confirmed' => 'boolean',
            'hours_confirmed' => 'boolean',
            'room_type_confirmed' => 'boolean',
            'room_college_confirmed' => 'boolean',
            'faculty_mismatch_confirmed' => 'boolean',
            'is_auto_generated' => 'boolean',
            'is_manually_modified' => 'boolean',
            'room_is_manual_override' => 'boolean',
            'auto_generated_meta' => 'array',
            'is_merged' => 'boolean',
            'merge_recommendation' => 'array',
        ];
    }

    /**
     * start_time/end_time are DB `time` columns, so MySQL/PDO hands
     * back "HH:mm:ss" (with seconds) on every read. Left as-is, that
     * raw string leaks straight through to the frontend and, for any
     * row the Registrar never re-touches via the Time picker, straight
     * back out to the Save Schedule payload — where it fails the
     * "HH:mm"-only date_format:H:i rule in Update/BatchUpdate...Request
     * ("Nothing saved — the rows.0.start_time field must match the
     * format H:i").
     *
     * These are plain Attribute accessors (not a `datetime:H:i` cast)
     * so start_time/end_time keep returning plain "HH:mm" *strings*
     * everywhere — several call sites (e.g.
     * SectionSubjectController::minutesBetween()) type-hint `string`
     * and do `explode(':', $value)`, which a Carbon object would
     * silently corrupt.
     */
    protected function startTime(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn (?string $value) => $value ? substr($value, 0, 5) : $value,
        );
    }

    protected function endTime(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn (?string $value) => $value ? substr($value, 0, 5) : $value,
        );
    }

    /**
     * `faculty_mismatch` is derived (see getFacultyMismatchAttribute()
     * below) rather than a real column, so it must be explicitly
     * appended to appear in JSON responses — every controller path
     * that serializes a SectionSubject already eager-loads `subject`
     * and `faculty` (see SectionSubjectController), so this is safe
     * to compute on every response without a fresh N+1.
     *
     * @var list<string>
     */
    protected $appends = ['faculty_mismatch'];

    /**
     * The Section this placement belongs to.
     *
     * @return BelongsTo<Section, SectionSubject>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * The master Subject placed into the Section.
     *
     * @return BelongsTo<Subject, SectionSubject>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * The Faculty assigned to teach this subject for this section.
     * Null until assigned by the scheduling engine.
     *
     * @return BelongsTo<Faculty, SectionSubject>
     */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    /**
     * The Room assigned for this subject's meetings. Null until
     * assigned by the scheduling engine.
     *
     * @return BelongsTo<Room, SectionSubject>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * INTELLIGENT IRREGULAR SECTION SCHEDULING — the Regular section's
     * class session this (Irregular section's) placement was merged
     * into, if any. Null for independently-scheduled or not-yet-
     * scheduled rows. See IrregularSectionMergeService.
     *
     * @return BelongsTo<SectionSubject, SectionSubject>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(SectionSubject::class, 'merged_into_section_subject_id');
    }

    /**
     * Every Irregular-section placement currently riding along on
     * THIS row's class session (the reverse of mergedInto()) — used
     * to compute the effective headcount a Room capacity check must
     * account for (this row's own Section's estimated_students plus
     * every merged-in Section's).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<SectionSubject>
     */
    public function mergedPlacements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SectionSubject::class, 'merged_into_section_subject_id');
    }

    /**
     * FACULTY MISMATCH — flags a manually-assigned Faculty who is
     * neither Teaching-Qualification-linked to this Subject nor from
     * the Subject's own academic home, so the Registrar/Dean can spot
     * (e.g.) a CCS faculty member manually placed on a BSED Minor
     * subject, or a CTE faculty member manually placed on a Rizal-
     * type General Education/Minor subject that isn't theirs to
     * teach. This is advisory only — the write path never blocks on
     * it (Manual Override is a deliberately supported path, see
     * RecommendationService's Manual Override tier) — it only labels
     * the result so the mismatch isn't silently invisible afterward.
     *
     * Mirrors RecommendationService::subjectCollegeId()'s definition
     * of a Subject's "academic home": null for General
     * Education/Minor subjects (no owning Major/Department/College),
     * otherwise the College that owns the Subject via its Major.
     * A faculty member is NOT a mismatch if either:
     *   - they carry an explicit Teaching Qualification for this
     *     exact Subject (`faculty_subject` pivot), regardless of
     *     College, or
     *   - their own `college_id` matches the Subject's academic home
     *     (General Education/Minor faculty for a GenEd/Minor Subject,
     *     or same-College faculty for a Major/Professional Subject).
     */
    public function getFacultyMismatchAttribute(): ?bool
    {
        if ($this->faculty_id === null) {
            return null;
        }

        $this->loadMissing(['subject.major.department', 'faculty.subjects']);

        $subject = $this->subject;
        $faculty = $this->faculty;

        if (! $subject || ! $faculty) {
            return null;
        }

        if ($faculty->subjects->contains('id', $subject->id)) {
            return false;
        }

        $subjectCollegeId = $subject->major?->department?->college_id;

        $isHomeMatch = $subjectCollegeId === null
            ? $faculty->college_id === null
            : $faculty->college_id === $subjectCollegeId;

        return ! $isHomeMatch;
    }
}