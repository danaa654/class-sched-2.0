<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCurriculumRequest;
use App\Http\Requests\UpdateCurriculumRequest;
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
            ->orderByDesc('start_year')
            ->orderBy('code')
            ->paginate(10, ['*'], 'curriculum_page')
            ->withQueryString();

        // Major dropdown for the Add/Edit dialog — Active majors only,
        // sorted alphabetically.
        $activeMajors = Major::query()
            ->where('status', 'Active')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return Inertia::render('Curriculum/Index', [
            'curriculums' => $curriculums,
            'activeMajors' => $activeMajors,
            'filters' => ['curriculum_search' => $search],
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