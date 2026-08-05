<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFacultyAvailabilityRequest;
use App\Http\Requests\UpdateFacultyAvailabilityRequest;
use App\Models\Faculty;
use App\Models\FacultyAvailability;
use Illuminate\Http\RedirectResponse;

class FacultyAvailabilityController extends Controller
{
    /**
     * Add a new weekly availability window for a faculty member.
     *
     * One record per day of week — enforced both here (validated
     * uniqueness) and at the database level as a backstop.
     */
    public function store(StoreFacultyAvailabilityRequest $request, Faculty $faculty): RedirectResponse
    {
        $faculty->availabilities()->create($request->validated());

        return redirect()
            ->route('scheduling.faculty.show', $faculty)
            ->with('success', 'Availability added successfully.');
    }

    /**
     * Update an existing availability window.
     */
    public function update(UpdateFacultyAvailabilityRequest $request, Faculty $faculty, FacultyAvailability $facultyAvailability): RedirectResponse
    {
        $facultyAvailability->update($request->validated());

        return redirect()
            ->route('scheduling.faculty.show', $faculty)
            ->with('success', 'Availability updated successfully.');
    }

    /**
     * Delete an availability window.
     */
    public function destroy(Faculty $faculty, FacultyAvailability $facultyAvailability): RedirectResponse
    {
        $facultyAvailability->delete();

        return redirect()
            ->route('scheduling.faculty.show', $faculty)
            ->with('success', 'Availability deleted successfully.');
    }
}