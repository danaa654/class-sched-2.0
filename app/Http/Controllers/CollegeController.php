<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCollegeRequest;
use App\Http\Requests\UpdateCollegeRequest;
use App\Models\College;
use Illuminate\Http\RedirectResponse;

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
     */
    public function destroy(College $college): RedirectResponse
    {
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