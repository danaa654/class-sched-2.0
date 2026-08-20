<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCurriculumRequest;
use App\Http\Requests\UpdateCurriculumRequest;
use App\Models\College;
use App\Models\Curriculum;
use App\Models\Major;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CurriculumController extends Controller
{
    /**
     * Display the Curriculum List page.
     *
     * Curriculum answers WHAT subjects belong to a Major (the plan
     * itself) — separate from the Academic Calendar, which answers WHEN
     * classes happen. This page only lists/manages Curriculums; building
     * out a Curriculum's subjects (the "Curriculum Builder") is a
     * separate, not-yet-built feature.
     */
    public function index(Request $request): Response
    {
        $this->authorize('manage-curriculum');

        $search = trim((string) $request->query('curriculum_search', ''));

        // Curriculum Year dropdown options — every distinct
        // (start_year, end_year) pair that actually exists on a
        // Curriculum record (including soft-deleted ones, same as the
        // list below, so a filter option never points at an empty
        // result set just because its Curriculums were archived/
        // deleted). Computed from the real start_year/end_year columns
        // (see the curriculums migration) — never parsed out of the
        // `code` string, which is free-form and not reliable for this.
        $curriculumYearOptions = Curriculum::query()
            ->withTrashed()
            ->select('start_year', 'end_year')
            ->distinct()
            ->orderByDesc('start_year')
            ->get()
            ->map(fn ($row) => [
                'start_year' => $row->start_year,
                'end_year' => $row->end_year,
                'value' => "{$row->start_year}-{$row->end_year}",
                'label' => "{$row->start_year}–{$row->end_year}",
            ])
            ->values();

        // Curriculum Year filter — "{start_year}-{end_year}", matched
        // directly against the structured start_year/end_year columns
        // (never string-matched against `code`). Only accepted when it
        // matches one of the real pairs computed above; anything else
        // is ignored rather than silently returning zero rows.
        $curriculumYear = trim((string) $request->query('curriculum_year', ''));
        $curriculumYearMatch = $curriculumYearOptions->firstWhere('value', $curriculumYear);

        // College filter — Curriculum has no direct college_id column;
        // College is reached through major.department.college, same
        // path SectionController::index() already uses for its own
        // College filter.
        $collegeId = $request->query('college_id');
        $collegeId = ($collegeId !== null && $collegeId !== '' && $collegeId !== 'all') ? (int) $collegeId : null;

        // Major/Program filter — validated against a real Major id
        // rather than trusted as-is.
        $majorId = $request->query('major_id');
        $majorId = ($majorId !== null && $majorId !== '' && $majorId !== 'all' && Major::query()->whereKey($majorId)->exists())
            ? (int) $majorId
            : null;

        $curriculums = Curriculum::query()
            ->withTrashed()
            ->with(['major' => fn ($query) => $query->withTrashed()])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('start_year', 'like', "%{$search}%")
                        ->orWhere('end_year', 'like', "%{$search}%")
                        ->orWhereHas('major', function ($majorQuery) use ($search) {
                            $majorQuery->withTrashed()->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($curriculumYearMatch !== null, function ($query) use ($curriculumYearMatch) {
                $query->where('start_year', $curriculumYearMatch['start_year'])
                    ->where('end_year', $curriculumYearMatch['end_year']);
            })
            ->when($collegeId !== null, function ($query) use ($collegeId) {
                $query->whereHas('major.department', function ($inner) use ($collegeId) {
                    $inner->where('college_id', $collegeId);
                });
            })
            ->when($majorId !== null, function ($query) use ($majorId) {
                $query->where('major_id', $majorId);
            })
            ->orderByDesc('start_year')
            ->orderBy('code')
            ->paginate(10, ['*'], 'curriculum_page')
            ->withQueryString();

        // Major dropdown for the Add/Edit dialog AND the Program filter
        // — Active majors only, sorted alphabetically. Each row carries
        // its own college_id (resolved through department, same as
        // SectionSubjectController's own Major payloads elsewhere) so
        // the frontend can narrow "All Programs" down to the selected
        // College's Programs client-side, without a second round-trip.
        $activeMajors = Major::query()
            ->where('status', 'Active')
            ->with('department:id,college_id')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'department_id'])
            ->map(fn ($major) => [
                'id' => $major->id,
                'name' => $major->name,
                'code' => $major->code,
                'college_id' => $major->department?->college_id,
            ]);

        // College filter dropdown for the list toolbar.
        $colleges = College::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return Inertia::render('Curriculum/Index', [
            'curriculums' => $curriculums,
            'activeMajors' => $activeMajors,
            'colleges' => $colleges,
            'curriculumYearOptions' => $curriculumYearOptions,
            'filters' => [
                'curriculum_search' => $search,
                'curriculum_year' => $curriculumYearMatch['value'] ?? '',
                'college_id' => $collegeId,
                'major_id' => $majorId,
            ],
        ]);
    }

    /**
     * Store a newly created curriculum.
     */
    public function store(StoreCurriculumRequest $request): RedirectResponse
    {
        $this->authorize('manage-curriculum');

        Curriculum::create($request->validated());

        return redirect()->route('curriculums')->with('success', 'Curriculum created successfully.');
    }

    /**
     * Update the specified curriculum.
     */
    public function update(UpdateCurriculumRequest $request, Curriculum $curriculum): RedirectResponse
    {
        $this->authorize('manage-curriculum');

        $curriculum->update($request->validated());

        return redirect()->route('curriculums')->with('success', 'Curriculum updated successfully.');
    }

    /**
     * Soft delete the specified curriculum.
     */
    public function destroy(Curriculum $curriculum): RedirectResponse
    {
        $this->authorize('manage-curriculum');

        $curriculum->delete();

        return redirect()->route('curriculums')->with('success', 'Curriculum deleted successfully.');
    }

    /**
     * Restore a soft-deleted curriculum.
     */
    public function restore(int $curriculum): RedirectResponse
    {
        $this->authorize('manage-curriculum');

        $record = Curriculum::onlyTrashed()->findOrFail($curriculum);
        $record->restore();

        return redirect()->route('curriculums')->with('success', 'Curriculum restored successfully.');
    }
}