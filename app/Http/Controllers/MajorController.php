<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMajorRequest;
use App\Http\Requests\UpdateMajorRequest;
use App\Models\Curriculum;
use App\Models\Major;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MajorController extends Controller
{
    /**
     * Display majors (paginated + searchable).
     *
     * Not currently bound to a route: the Academic Structure page renders
     * Colleges, Departments, and Majors together in a single Inertia visit,
     * owned by AcademicStructureController@index, so this page doesn't need
     * its own dedicated URL right now. Kept here — fully working — as the
     * natural place this query lives, and ready to wire to a route directly
     * later if Majors ever needs a standalone view.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('major_search', ''));

        $majors = Major::query()
            ->withTrashed()
            ->with([
                'department' => fn ($query) => $query->withTrashed()
                    ->with(['college' => fn ($collegeQuery) => $collegeQuery->withTrashed()]),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhereHas('department', function ($departmentQuery) use ($search) {
                            $departmentQuery->withTrashed()->where('name', 'like', "%{$search}%")
                                ->orWhereHas('college', function ($collegeQuery) use ($search) {
                                    $collegeQuery->withTrashed()->where('name', 'like', "%{$search}%");
                                });
                        });
                });
            })
            ->orderBy('name')
            ->paginate(10, ['*'], 'major_page')
            ->withQueryString();

        return Inertia::render('AcademicStructure/Index', [
            'majors' => $majors,
            'filters' => ['major_search' => $search],
        ]);
    }

    /**
     * Store a newly created major.
     */
    public function store(StoreMajorRequest $request): RedirectResponse
    {
        Major::create($request->validated());

        return redirect()->route('academic-structure')->with('success', 'Major created successfully.');
    }

    /**
     * Update the specified major.
     */
    public function update(UpdateMajorRequest $request, Major $major): RedirectResponse
    {
        $major->update($request->validated());

        return redirect()->route('academic-structure')->with('success', 'Major updated successfully.');
    }

    /**
     * Soft delete the specified major.
     *
     * DELETE GATE — blocked outright when this Major still has any
     * active (non-trashed) dependents: Sections, Curriculums, or
     * Subjects. This matters more here than at College/Department:
     * `sections.major_id`'s foreign key has no cascade/restrict
     * behavior configured at the DB level, so without this
     * application-level check, deleting a Major with existing
     * Sections would surface as a raw, unfriendly DB constraint
     * error instead of a clear message. `curriculums.major_id` DOES
     * cascadeOnDelete at the DB level — but that only fires on a
     * real row delete, never on this soft delete, so Curriculums are
     * never silently destroyed by this action; the check below is
     * purely to stop the Registrar from archiving a Major that's
     * still actively in use, not to prevent DB-level data loss that
     * couldn't happen here anyway.
     */
    public function destroy(Major $major): RedirectResponse
    {
        $blockers = [];

        $sectionCount = Section::query()->where('major_id', $major->id)->count();
        if ($sectionCount > 0) {
            $blockers[] = $sectionCount === 1 ? '1 section' : "{$sectionCount} sections";
        }

        $curriculumCount = Curriculum::query()->where('major_id', $major->id)->count();
        if ($curriculumCount > 0) {
            $blockers[] = $curriculumCount === 1 ? '1 curriculum' : "{$curriculumCount} curriculums";
        }

        $subjectCount = Subject::query()->where('major_id', $major->id)->count();
        if ($subjectCount > 0) {
            $blockers[] = $subjectCount === 1 ? '1 subject' : "{$subjectCount} subjects";
        }

        if (! empty($blockers)) {
            throw ValidationException::withMessages([
                'code' => "This major can't be deleted — it still has ".implode(', ', $blockers).' attached to it. Remove or reassign those first.',
            ]);
        }

        $major->delete();

        return redirect()->route('academic-structure')->with('success', 'Major deleted successfully.');
    }

    /**
     * Restore a soft-deleted major.
     */
    public function restore(int $major): RedirectResponse
    {
        $record = Major::onlyTrashed()->findOrFail($major);
        $record->restore();

        return redirect()->route('academic-structure')->with('success', 'Major restored successfully.');
    }

    /**
     * Permanently delete an already-soft-deleted major — the "clean it
     * up for good" action for rows already sitting in the Deleted state.
     * Same gate as the rest of Academic Structure. Only reachable for a
     * row that's already trashed; a still-active major must go through
     * destroy() first.
     *
     * Re-runs the same attachment check as destroy(): something could
     * have been re-pointed at this major's id after it was soft deleted,
     * and a permanent delete can't be undone the way the soft delete
     * could.
     */
    public function forceDelete(Request $request, int $major): RedirectResponse
    {
        $this->authorize('manage-academic-structure');

        $record = Major::onlyTrashed()->findOrFail($major);

        $blockers = [];

        $sectionCount = Section::query()->where('major_id', $record->id)->count();
        if ($sectionCount > 0) {
            $blockers[] = $sectionCount === 1 ? '1 section' : "{$sectionCount} sections";
        }

        $curriculumCount = Curriculum::query()->where('major_id', $record->id)->count();
        if ($curriculumCount > 0) {
            $blockers[] = $curriculumCount === 1 ? '1 curriculum' : "{$curriculumCount} curriculums";
        }

        $subjectCount = Subject::query()->where('major_id', $record->id)->count();
        if ($subjectCount > 0) {
            $blockers[] = $subjectCount === 1 ? '1 subject' : "{$subjectCount} subjects";
        }

        if (! empty($blockers)) {
            throw ValidationException::withMessages([
                'code' => "This major can't be permanently deleted — it still has ".implode(', ', $blockers).' attached to it. Remove or reassign those first.',
            ]);
        }

        $name = $record->name;
        $record->forceDelete();

        return redirect()->route('academic-structure')->with('success', "{$name} was permanently deleted.");
    }
}