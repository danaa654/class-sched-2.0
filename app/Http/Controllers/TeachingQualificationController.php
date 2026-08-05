<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFacultyQualificationsRequest;
use App\Models\Faculty;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeachingQualificationController extends Controller
{
    /**
     * Display the Teaching Qualifications page.
     *
     * Left panel lists faculty to pick from; right panel shows the
     * selected faculty member's info and lets the admin assign which
     * Active subjects they're qualified to teach. This module only
     * stores the Faculty <-> Subject relationship — no schedules,
     * rooms, sections, or timeslots are touched here.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('faculty_search', ''));
        $selectedFacultyId = $request->query('faculty_id');

        $faculties = Faculty::query()
            ->with(['department' => fn ($query) => $query->withTrashed()])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('faculty_id', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhereHas('department', function ($departmentQuery) use ($search) {
                            $departmentQuery->withTrashed()->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $selectedFaculty = null;

        if ($selectedFacultyId) {
            $selectedFaculty = Faculty::query()
                ->with([
                    'department' => fn ($query) => $query->withTrashed(),
                    'subjects' => fn ($query) => $query->orderBy('subject_code'),
                ])
                ->find($selectedFacultyId);
        }

        return Inertia::render('Scheduling/TeachingQualifications/Index', [
            'faculties' => $faculties,
            'filters' => ['faculty_search' => $search],
            'selectedFaculty' => $selectedFaculty,
            'subjects' => Subject::query()
                ->where('is_active', true)
                ->orderBy('subject_code')
                ->get(['id', 'subject_code', 'subject_title', 'category', 'units']),
        ]);
    }

    /**
     * Sync the set of subjects a faculty member is qualified to teach.
     *
     * A full replace (sync), not an append — the right panel always
     * sends the complete desired set of subject ids. Duplicate
     * assignments are impossible by construction: sync() de-dupes and
     * the underlying table also has a unique(faculty_id, subject_id)
     * constraint as a backstop.
     */
    public function update(UpdateFacultyQualificationsRequest $request, Faculty $faculty): RedirectResponse
    {
        $faculty->subjects()->sync($request->validated('subject_ids'));

        return redirect()
            ->route('scheduling.teaching-qualifications', ['faculty_id' => $faculty->id])
            ->with('success', 'Teaching qualifications updated successfully.');
    }
}