<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFacultyQualificationsRequest;
use App\Models\Faculty;
use Illuminate\Http\RedirectResponse;

/**
 * Teaching Qualifications no longer has a standalone page — it's the
 * "Teaching Qualifications" tab on the Faculty Details page
 * (Scheduling/Faculty/Details.vue), which duplicated this module's UI
 * exactly. Only the sync endpoint remains.
 */
class TeachingQualificationController extends Controller
{
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
            ->route('scheduling.faculty.show', $faculty)
            ->with('success', 'Teaching qualifications updated successfully.');
    }
}