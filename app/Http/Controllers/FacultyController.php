<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFacultyRequest;
use App\Http\Requests\UpdateFacultyRequest;
use App\Models\College;
use App\Models\Faculty;
use App\Models\Subject;
use App\Services\FacultyWorkloadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FacultyController extends Controller
{
    public function __construct(
        private readonly FacultyWorkloadService $workloadService
    ) {
    }

    /**
     * Display the Faculty Master page.
     *
     * Faculty members here are NOT system users — they never log in.
     * This is purely a roster the registrar/admin maintains so the
     * scheduling module has faculty to draw from later. No subject,
     * schedule, room, or section assignment happens here.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('faculty_search', ''));
        $category = $request->query('faculty_category', '');
        $category = in_array($category, ['Department Faculty', 'General Education Faculty'], true) ? $category : '';

        $faculties = Faculty::query()
            ->with(['college' => fn ($query) => $query->withTrashed()])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('faculty_id', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhereHas('college', function ($collegeQuery) use ($search) {
                            $collegeQuery->withTrashed()->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($category !== '', fn ($query) => $category === 'General Education Faculty'
                ? $query->whereNull('college_id')
                : $query->whereNotNull('college_id'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(10, ['*'], 'faculty_page')
            ->withQueryString();

        // FACULTY WORKLOAD VALIDATION — "Dashboard Indicators". Each
        // row gets its real current/max/remaining load (Scheduled +
        // Draft placements, active semester only) and a 🟢/🟡/🔴
        // status so the roster doubles as an at-a-glance overload
        // report, computed via FacultyWorkloadService — the same
        // engine Auto Generate/Recommend/Manual Assignment/Save
        // Schedule use — so this can never disagree with those.
        $faculties->getCollection()->transform(function (Faculty $faculty) {
            $evaluation = $this->workloadService->evaluate($faculty);
            $faculty->setAttribute('workload', $evaluation);

            return $faculty;
        });

        return Inertia::render('Scheduling/Faculty/Index', [
            'faculties' => $faculties,
            'filters' => ['faculty_search' => $search, 'faculty_category' => $category],
            'colleges' => College::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'nextFacultyId' => $this->nextFacultyId(),
        ]);
    }

    /**
     * Display the Faculty Details page (Information, Teaching
     * Qualifications, Availability, and Workload tabs).
     */
    public function show(Faculty $faculty): Response
    {
        $faculty->load([
            'college' => fn ($query) => $query->withTrashed(),
            'subjects' => fn ($query) => $query->orderBy('subject_code'),
            'availabilities',
        ]);

        // Real assigned workload (Scheduled + Draft placements, active
        // semester only) — see FacultyController@index docblock above.
        // includePlacements: true here (and only here) so the Workload
        // tab can list *which* subjects/sections make up the load
        // figure, not just the summary numbers.
        $faculty->setAttribute('workload', $this->workloadService->evaluate($faculty, includePlacements: true));

        return Inertia::render('Scheduling/Faculty/Details', [
            'faculty' => $faculty,
            'colleges' => College::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'subjects' => Subject::query()
                ->where('is_active', true)
                ->orderBy('subject_code')
                ->get(['id', 'subject_code', 'subject_title', 'category', 'units']),
        ]);
    }

    /**
     * Store a newly created faculty member in the Faculty Master.
     */
    public function store(StoreFacultyRequest $request): RedirectResponse
    {
        Faculty::create($request->validated());

        return redirect()->route('scheduling.faculty')->with('success', 'Faculty member added successfully.');
    }

    /**
     * Update an existing faculty member in the Faculty Master.
     */
    public function update(UpdateFacultyRequest $request, Faculty $faculty): RedirectResponse
    {
        $faculty->update($request->validated());

        return redirect()->route('scheduling.faculty')->with('success', 'Faculty member updated successfully.');
    }

    /**
     * Delete a faculty member from the Faculty Master.
     */
    public function destroy(Faculty $faculty): RedirectResponse
    {
        $faculty->delete();

        return redirect()->route('scheduling.faculty')->with('success', 'Faculty member deleted successfully.');
    }

    /**
     * Determine the next sequential Faculty ID, e.g. FAC-2026-0001.
     *
     * This is only a suggestion pre-filled into the Add Faculty form —
     * the registrar/admin can still edit it freely before saving, and
     * uniqueness is always re-checked server-side on store.
     */
    private function nextFacultyId(): string
    {
        $year = now()->year;
        $prefix = "FAC-{$year}-";

        $lastId = Faculty::withTrashed()
            ->where('faculty_id', 'like', "{$prefix}%")
            ->orderByDesc('faculty_id')
            ->value('faculty_id');

        $nextNumber = $lastId
            ? ((int) substr($lastId, strlen($prefix))) + 1
            : 1;

        return $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}