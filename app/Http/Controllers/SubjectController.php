<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubjectRequest;
use App\Http\Requests\UpdateSubjectRequest;
use App\Models\Major;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubjectController extends Controller
{
    /**
     * Display the Subject Library page.
     *
     * Subjects here form the MASTER LIST of every subject offered by the
     * institution — this is not the Curriculum. The Curriculum will
     * reference these subjects later.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('subject_search', ''));

        $subjects = Subject::query()
            ->with(['major' => fn ($query) => $query->withTrashed()])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('subject_code', 'like', "%{$search}%")
                        ->orWhere('subject_title', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhereHas('major', function ($majorQuery) use ($search) {
                            $majorQuery->withTrashed()->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('subject_code')
            ->paginate(10, ['*'], 'subject_page')
            ->withQueryString();

        return Inertia::render('Subjects/Index', [
            'subjects' => $subjects,
            'filters' => ['subject_search' => $search],
            'majors' => Major::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Store a newly created subject in the Subject Library.
     */
    public function store(StoreSubjectRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        Subject::create($data);

        return redirect()->route('subjects')->with('success', 'Subject created successfully.');
    }

    /**
     * Update an existing subject in the Subject Library.
     */
    public function update(UpdateSubjectRequest $request, Subject $subject): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        $subject->update($data);

        return redirect()->route('subjects')->with('success', 'Subject updated successfully.');
    }

    /**
     * Delete a subject from the Subject Library.
     *
     * Blocked if the subject is already mapped into any Curriculum —
     * the Curriculum only ever references the master Subject, so
     * deleting it here would silently break that Curriculum's structure.
     */
    public function destroy(Subject $subject): RedirectResponse
    {
        if ($subject->curriculumItems()->exists()) {
            return redirect()->route('subjects')->with(
                'error',
                'This subject is used in one or more curriculums and cannot be deleted. Remove it from those curriculums first.',
            );
        }

        $subject->delete();

        return redirect()->route('subjects')->with('success', 'Subject deleted successfully.');
    }
}