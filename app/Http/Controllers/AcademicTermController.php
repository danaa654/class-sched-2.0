<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAcademicTermRequest;
use App\Http\Requests\UpdateAcademicTermRequest;
use App\Models\AcademicTerm;
use App\Models\SchoolYear;
use App\Models\Semester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcademicTermController extends Controller
{
    /**
     * Display academic terms (paginated + searchable).
     *
     * Not bound to its own route: the Academic Calendar page renders
     * Academic Terms in a single Inertia visit, owned by
     * AcademicCalendarController@index. Kept here as the natural place
     * this query lives, and reused directly by that controller.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('academic_term_search', ''));

        $academicTerms = AcademicTerm::query()
            ->withTrashed()
            ->with(['schoolYear', 'semester'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->whereHas('schoolYear', function ($sy) use ($search) {
                        $sy->where('name', 'like', "%{$search}%");
                    })->orWhereHas('semester', function ($sem) use ($search) {
                        $sem->where('name', 'like', "%{$search}%")
                            ->orWhere('short_name', 'like', "%{$search}%");
                    });
                });
            })
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'academic_term_page')
            ->withQueryString();

        return Inertia::render('AcademicCalendar/Index', [
            'academicTerms' => $academicTerms,
            'filters' => ['academic_term_search' => $search],
        ]);
    }

    /**
     * Store a newly created academic term.
     *
     * The Academic Term form is the single place School Year (Start
     * Year/End Year), Semester, and Scheduling Preferences are all
     * entered together. The School Year itself is found-or-created
     * here from start_year/end_year — the form never exposes a
     * School Year picker or a separate School Year Add/Edit screen.
     *
     * The single-Active-record rule for Academic Term is enforced in
     * the AcademicTerm model's `saved` hook, not here.
     */
    public function store(StoreAcademicTermRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $schoolYear = $this->resolveSchoolYear($validated);
        $semester = $this->resolveSemester($validated['semester']);

        AcademicTerm::create([
            'school_year_id' => $schoolYear->id,
            'semester_id' => $semester->id,
            'status' => $validated['status'],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return redirect()->route('academic-calendar')->with('success', 'Academic term created successfully.');
    }

    /**
     * Update the specified academic term.
     *
     * Mirrors store(): the School Year (and its Scheduling Preferences)
     * behind this Academic Term is resolved/updated from the same
     * submission, then the Academic Term itself is updated.
     */
    public function update(UpdateAcademicTermRequest $request, AcademicTerm $academicTerm): RedirectResponse
    {
        $validated = $request->validated();

        $schoolYear = $this->resolveSchoolYear($validated);
        $semester = $this->resolveSemester($validated['semester']);

        $academicTerm->update([
            'school_year_id' => $schoolYear->id,
            'semester_id' => $semester->id,
            'status' => $validated['status'],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return redirect()->route('academic-calendar')->with('success', 'Academic term updated successfully.');
    }

    /**
     * Soft delete the specified academic term.
     *
     * DELETE GATE — mirrors archive()'s "End Semester" gate below,
     * but stricter: deleting the term itself must never be usable as
     * a backdoor around a finalized/in-progress section that the
     * corresponding Section-delete flow (SectionController::destroy())
     * already protects with its own confirmation/typed-name steps.
     * Blocks outright (no override) when this term has any Section
     * that is either:
     *   - finalized (is_finalized), or
     *   - already has real scheduling progress on it (any subject
     *     with Faculty/Room/Days/Start/End assigned — the exact same
     *     "assigned" definition SectionController::index()'s
     *     assigned_subjects_count and finalize() already use, so
     *     there's no second/competing definition of "scheduled"
     *     introduced here).
     *
     * A term with no Sections, or only Sections that have no subjects
     * yet or subjects that were never actually scheduled, deletes
     * normally — there's nothing at risk to protect there.
     */
    public function destroy(AcademicTerm $academicTerm): RedirectResponse
    {
        $finalizedSectionCodes = $academicTerm->matchingSectionsQuery()
            ->where('is_finalized', true)
            ->orderBy('section_code')
            ->pluck('section_code');

        if ($finalizedSectionCodes->isNotEmpty()) {
            throw ValidationException::withMessages([
                'status' => "This term can't be deleted — the following sections have a finalized schedule: "
                    .$finalizedSectionCodes->implode(', ').'. Unlock them first if this term truly needs to be removed.',
            ]);
        }

        $scheduledSectionCodes = $academicTerm->matchingSectionsQuery()
            ->whereHas('sectionSubjects', function ($query) {
                $query->whereNotNull('faculty_id')
                    ->whereNotNull('room_id')
                    ->whereNotNull('days')
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time');
            })
            ->orderBy('section_code')
            ->pluck('section_code');

        if ($scheduledSectionCodes->isNotEmpty()) {
            throw ValidationException::withMessages([
                'status' => "This term can't be deleted — the following sections already have subjects scheduled: "
                    .$scheduledSectionCodes->implode(', ').'. Clear their schedules first, or delete those sections individually if they\'re no longer needed.',
            ]);
        }

        $academicTerm->delete();

        return redirect()->route('academic-calendar')->with('success', 'Academic term deleted successfully.');
    }

    /**
     * Restore a soft-deleted academic term.
     */
    public function restore(int $academicTerm): RedirectResponse
    {
        $record = AcademicTerm::onlyTrashed()->findOrFail($academicTerm);
        $record->restore();

        return redirect()->route('academic-calendar')->with('success', 'Academic term restored successfully.');
    }

    /**
     * Archive the specified academic term — the "End Semester" action.
     *
     * Only an Inactive term may be archived — an Active term must be
     * switched to Inactive first (or superseded by making a different
     * term Active, which auto-flips it via AcademicTerm::booted()).
     *
     * END SEMESTER GATE: every Section belonging to this term
     * (matchingSectionsQuery() — same School Year + Semester
     * resolution the Sections list and Dashboard already use) must be
     * finalized first. A term with NO Sections at all is allowed
     * through — there's nothing to protect, and blocking an
     * empty/unused term just creates needless friction. This is a
     * real backend gate, not just a UI hint: even a scripted/direct
     * request to this endpoint is stopped here. Beyond that gate,
     * archiving is still a pure status flip on the term itself — it
     * never touches, migrates, or cascades to the Sections
     * (finalization already happened section-by-section as the
     * precondition, so there's nothing left to write onto them here).
     *
     * The frontend only shows the Archive/"End Semester" action for
     * Inactive terms with every Section finalized, but that's a UI
     * convenience, not enforcement, so both rules are re-checked here
     * against a direct request.
     */
    public function archive(AcademicTerm $academicTerm): RedirectResponse
    {
        if ($academicTerm->status !== 'Inactive') {
            throw ValidationException::withMessages([
                'status' => 'Only an Inactive academic term can be archived.',
            ]);
        }

        // The unfinalized Sections are named explicitly (not just
        // counted) so the Admin/Registrar can jump straight to
        // finishing them instead of hunting through the Sections list.
        $unfinalizedSectionCodes = $academicTerm->matchingSectionsQuery()
            ->where('is_finalized', false)
            ->orderBy('section_code')
            ->pluck('section_code');

        if ($unfinalizedSectionCodes->isNotEmpty()) {
            throw ValidationException::withMessages([
                'status' => "This term can't be archived yet — the following sections still need to be finalized first: "
                    .$unfinalizedSectionCodes->implode(', ').'.',
            ]);
        }

        $academicTerm->update(['status' => 'Archived']);

        return redirect()->route('academic-calendar')->with('success', 'Academic term archived successfully.');
    }

    /**
     * CONCURRENCY/DATA-INTEGRITY GUARD — before ever writing new
     * Scheduling Preferences (Class Start/End Time, Available Class
     * Days) onto an EXISTING School Year, check every already-
     * scheduled SectionSubject under that School Year (across every
     * Semester, since Scheduling Preferences live on the School Year,
     * not the Academic Term — see resolveSchoolYear()'s docblock)
     * still fits inside the proposed window/days.
     *
     * Without this, shrinking the window (e.g. Class Start Time
     * 8:00 AM -> 9:00 AM, or dropping Saturday) would silently strand
     * already-saved schedules outside the very policy that's
     * supposed to govern them — the Room Grid/Subjects page would
     * keep showing them as "Scheduled" with no indication they now
     * violate the active calendar, and the Auto Schedule AI would
     * treat the window as authoritative while these leftover rows
     * quietly disagree with it.
     *
     * A brand-new School Year (not yet persisted) is skipped — it
     * can't have any schedules yet, so there's nothing to strand.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException  listing which already-scheduled
     *         subjects would fall outside the proposed window/days,
     *         so the Admin/Registrar can fix or clear those first
     *         instead of the change being silently allowed.
     */
    private function assertNoOutOfWindowSchedules(SchoolYear $schoolYear, array $validated): void
    {
        if (! $schoolYear->exists) {
            return;
        }

        $newStartMinutes = $this->toMinutes($validated['class_start_time']);
        $newEndMinutes = $this->toMinutes($validated['class_end_time']);
        $newAvailableDays = $validated['available_days'];

        $scheduled = \App\Models\SectionSubject::query()
            ->whereHas('section', fn ($q) => $q->where('academic_year', $schoolYear->name))
            ->whereNotNull('days')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->with(['section:id,section_code', 'subject:id,subject_code'])
            ->get();

        $timeConflicts = [];
        $dayConflicts = [];

        foreach ($scheduled as $sectionSubject) {
            $dayTokens = array_filter(explode(',', (string) $sectionSubject->days));
            $label = sprintf(
                '%s (%s) — %s %s-%s',
                $sectionSubject->subject?->subject_code ?? 'Subject',
                $sectionSubject->section?->section_code ?? 'Section',
                implode('/', $dayTokens),
                $sectionSubject->start_time,
                $sectionSubject->end_time
            );

            $outOfDays = array_diff($dayTokens, $newAvailableDays);
            if (! empty($outOfDays)) {
                $dayConflicts[] = $label;
            }

            $startMinutes = $this->toMinutes((string) $sectionSubject->start_time);
            $endMinutes = $this->toMinutes((string) $sectionSubject->end_time);
            if ($startMinutes < $newStartMinutes || $endMinutes > $newEndMinutes) {
                $timeConflicts[] = $label;
            }
        }

        if (empty($timeConflicts) && empty($dayConflicts)) {
            return;
        }

        $summarize = fn (array $items) => implode('; ', array_slice($items, 0, 3))
            .(count($items) > 3 ? ' (+'.(count($items) - 3).' more)' : '');

        $errors = [];

        if (! empty($timeConflicts)) {
            $errors['class_start_time'] = 'Cannot save: '.count($timeConflicts)
                .' already-scheduled subject(s) fall outside this window — '.$summarize($timeConflicts)
                .'. Reschedule or remove them first, or widen the window.';
        }

        if (! empty($dayConflicts)) {
            $errors['available_days'] = 'Cannot save: '.count($dayConflicts)
                .' already-scheduled subject(s) use a day being removed — '.$summarize($dayConflicts)
                .'. Reschedule or remove them first.';
        }

        throw ValidationException::withMessages($errors);
    }

    /**
     * Minutes-since-midnight for an "H:i"/"H:i:s" time string. Small,
     * local helper — SchoolYear's own toMinutes()/fromMinutes() are
     * private to that model, and this check needs to compare against
     * the SUBMITTED (not-yet-saved) window, so it can't just call
     * $schoolYear->isWithinSchedulingPolicy() either.
     */
    private function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }

    /**
     * Find the School Year matching the submitted Start Year/End Year
     * (creating it — Active by default — the first time it's used),
     * and always sync its Scheduling Preferences to whatever was just
     * submitted on the Academic Term form. Lunch Break is always
     * forced to the fixed 12:00 PM - 1:00 PM window regardless of
     * input (see SchoolYear::LUNCH_BREAK_START/END).
     */
    private function resolveSchoolYear(array $validated): SchoolYear
    {
        $name = "{$validated['start_year']}-{$validated['end_year']}";

        $schoolYear = SchoolYear::withTrashed()->where('name', $name)->first();

        if (! $schoolYear) {
            $schoolYear = new SchoolYear();
            $schoolYear->name = $name;
            $schoolYear->start_year = $validated['start_year'];
            $schoolYear->end_year = $validated['end_year'];
            $schoolYear->status = 'Active';
        } elseif ($schoolYear->trashed()) {
            $schoolYear->restore();
        }

        $this->assertNoOutOfWindowSchedules($schoolYear, $validated);

        $schoolYear->class_start_time = $validated['class_start_time'];
        $schoolYear->class_end_time = $validated['class_end_time'];
        $schoolYear->time_interval = SchoolYear::DEFAULT_TIME_INTERVAL_MINUTES;
        $schoolYear->available_days = $validated['available_days'];
        $schoolYear->lunch_start = SchoolYear::LUNCH_BREAK_START;
        $schoolYear->lunch_end = SchoolYear::LUNCH_BREAK_END;
        $schoolYear->save();

        return $schoolYear;
    }

    /**
     * Find the Semester matching the submitted name (one of
     * Semester::NAMES — "1st Semester", "2nd Semester", "Summer"),
     * creating it the first time it's actually used. There's no
     * Semester picker/CRUD screen anymore; the dropdown on the
     * Academic Term form is a fixed list and this is where a real
     * Semester record gets created behind the scenes on first use.
     */
    private function resolveSemester(string $name): Semester
    {
        $semester = Semester::withTrashed()->where('name', $name)->first();

        if ($semester) {
            if ($semester->trashed()) {
                $semester->restore();
            }

            return $semester;
        }

        $defaults = Semester::defaultsFor($name);

        return Semester::create([
            'name' => $name,
            'short_name' => $defaults['short_name'],
            'display_order' => $defaults['display_order'],
            'status' => 'Active',
        ]);
    }
}