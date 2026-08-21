<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCollegeRequest;
use App\Http\Requests\UpdateCollegeRequest;
use App\Models\College;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Room;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class CollegeController extends Controller
{
    /**
     * Store a newly created college.
     */
    public function store(StoreCollegeRequest $request): RedirectResponse
    {
        College::create($request->validated());

        return redirect()->route('academic-structure')->with('success', 'College created successfully.');
    }

    /**
     * Update the specified college.
     */
    public function update(UpdateCollegeRequest $request, College $college): RedirectResponse
    {
        $college->update($request->validated());

        return redirect()->route('academic-structure')->with('success', 'College updated successfully.');
    }

    /**
     * Soft delete the specified college.
     *
     * DELETE GATE — same reasoning as MajorController::destroy()/
     * DepartmentController::destroy(): blocked while any active
     * (non-trashed) Department, Faculty, Subject (its optional
     * "primary college" convenience field), Room, or User still
     * belongs to this College.
     *
     * `faculties.college_id` actually `restrictOnDelete()`s at the DB
     * level — a real delete would already be rejected there — but
     * this application-level check still matters for the SAME reason
     * as the two controllers above: it's what turns a raw DB
     * constraint failure into a clear message, and it also covers
     * Department/Subject/Room/User, none of which restrict at the DB
     * level (they nullOnDelete/cascadeOnDelete instead, and even
     * then only on a real delete, never on this soft delete).
     */
    public function destroy(College $college): RedirectResponse
    {
        $blockers = [];

        $departmentCount = Department::query()->where('college_id', $college->id)->count();
        if ($departmentCount > 0) {
            $blockers[] = $departmentCount === 1 ? '1 department' : "{$departmentCount} departments";
        }

        $facultyCount = Faculty::query()->where('college_id', $college->id)->count();
        if ($facultyCount > 0) {
            $blockers[] = $facultyCount === 1 ? '1 faculty member' : "{$facultyCount} faculty members";
        }

        $subjectCount = Subject::query()->where('college_id', $college->id)->count();
        if ($subjectCount > 0) {
            $blockers[] = $subjectCount === 1 ? '1 subject' : "{$subjectCount} subjects";
        }

        $roomCount = Room::query()->where('college_id', $college->id)->count();
        if ($roomCount > 0) {
            $blockers[] = $roomCount === 1 ? '1 room' : "{$roomCount} rooms";
        }

        $userCount = User::query()->where('college_id', $college->id)->count();
        if ($userCount > 0) {
            $blockers[] = $userCount === 1 ? '1 user' : "{$userCount} users";
        }

        if (! empty($blockers)) {
            throw ValidationException::withMessages([
                'code' => "This college can't be deleted — it still has ".implode(', ', $blockers).' attached to it. Remove or reassign those first.',
            ]);
        }

        $college->delete();

        return redirect()->route('academic-structure')->with('success', 'College deleted successfully.');
    }

    /**
     * Restore a soft-deleted college.
     */
    public function restore(int $college): RedirectResponse
    {
        $record = College::onlyTrashed()->findOrFail($college);
        $record->restore();

        return redirect()->route('academic-structure')->with('success', 'College restored successfully.');
    }
}