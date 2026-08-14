<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubjectRequest;
use App\Http\Requests\UpdateSubjectRequest;
use App\Models\College;
use App\Models\Major;
use App\Models\Subject;
use App\Support\AccessScope;
use App\Support\RoomCategories;
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
        $this->authorize('viewAny', Subject::class);

        $user = $request->user();
        $search = trim((string) $request->query('subject_search', ''));

        // Every authorized role can VIEW the full Subject Library (Dean/
        // OIC need to see GenEd/Minor subjects to schedule their own
        // sections) — the Add/Edit/Delete actions are what's scoped,
        // enforced per-record below and in the frontend via `canManage`.
        $subjects = Subject::query()
            ->with(['college:id,code,name', 'majors:id,name,code,department_id'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('subject_code', 'like', "%{$search}%")
                        ->orWhere('subject_title', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhereHas('majors', function ($majorQuery) use ($search) {
                            $majorQuery->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('subject_code')
            ->paginate(10, ['*'], 'subject_page')
            ->withQueryString();

        // Tell the frontend, per row, whether the current user may
        // modify this subject's definition — UI-level enforcement on
        // top of (never instead of) the server-side policy checks in
        // update()/destroy() below.
        $subjects->getCollection()->transform(function (Subject $subject) use ($request) {
            $subject->setAttribute('can_manage', $request->user()->can('update', $subject));

            return $subject;
        });

        return Inertia::render('Subjects/Index', [
            'subjects' => $subjects,
            'filters' => ['subject_search' => $search],
            'colleges' => College::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'majors' => Major::query()
                ->where('status', 'Active')
                ->with('department:id,college_id')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'department_id'])
                ->map(fn (Major $major) => [
                    'id' => $major->id,
                    'name' => $major->name,
                    'code' => $major->code,
                    'college_id' => $major->department?->college_id,
                ]),
            'roomCategories' => RoomCategories::LIST,
            // What this user is allowed to pick, so the form never even
            // offers an option the backend would reject — mirrored by
            // the FormRequest/Policy checks server-side either way.
            'subjectAccess' => [
                'categoryOptions' => $this->categoryOptionsFor($user),
                'lockedCollegeId' => AccessScope::collegeId($user),
                'isCollegeScoped' => AccessScope::isCollegeScoped($user),
                'isAssistantDean' => AccessScope::isAssistantDean($user),
            ],
        ]);
    }

    /**
     * Store a newly created subject in the Subject Library.
     */
    public function store(StoreSubjectRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $majorIds = array_values(array_unique(array_filter($data['major_ids'] ?? [])));
        unset($data['major_ids']);

        $data['is_active'] = $data['is_active'] ?? true;
        $data['major_id'] = $majorIds[0] ?? null;

        $this->authorize('createOfCategory', [Subject::class, $data['category'], $data['college_id'] ?? null]);

        $subject = Subject::create($data);
        $subject->majors()->sync($majorIds);

        return redirect()->route('subjects')->with('success', 'Subject created successfully.');
    }

    /**
     * Update an existing subject in the Subject Library.
     */
    public function update(UpdateSubjectRequest $request, Subject $subject): RedirectResponse
    {
        $this->authorize('update', $subject);

        $data = $request->validated();
        $majorIds = array_values(array_unique(array_filter($data['major_ids'] ?? [])));
        unset($data['major_ids']);

        $data['is_active'] = $data['is_active'] ?? true;
        $data['major_id'] = $majorIds[0] ?? null;

        $subject->update($data);
        $subject->majors()->sync($majorIds);

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
        $this->authorize('delete', $subject);

        if ($subject->curriculumItems()->exists()) {
            return redirect()->route('subjects')->with(
                'error',
                'This subject is used in one or more curriculums and cannot be deleted. Remove it from those curriculums first.',
            );
        }

        $subject->delete();

        return redirect()->route('subjects')->with('success', 'Subject deleted successfully.');
    }

    /**
     * The subject Category values this user's role is allowed to
     * pick from, for populating the Add/Edit Subject form's Category
     * select — Admin/Registrar get all three, Assistant Dean is
     * restricted to the shared types, Dean/OIC to Major only.
     *
     * @return array<int, string>
     */
    private function categoryOptionsFor(?\App\Models\User $user): array
    {
        if (AccessScope::isUnrestricted($user)) {
            return ['Major', 'General Education', 'Minor'];
        }

        if (AccessScope::isAssistantDean($user)) {
            return ['General Education', 'Minor'];
        }

        return ['Major'];
    }
}