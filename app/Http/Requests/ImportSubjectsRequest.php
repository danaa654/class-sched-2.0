<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the uploaded file for the Subject Library's Bulk Import
 * (see SubjectController::import()). Only the file itself is checked
 * here — every individual row's data is validated separately inside
 * the controller (each row can succeed or fail independently, unlike
 * a single-record FormRequest that lives or dies as one payload).
 */
class ImportSubjectsRequest extends FormRequest
{
    /**
     * Row-level authorization (Dean/OIC vs Assistant Dean vs Admin/
     * Registrar scope) is enforced per-row in the controller, mirroring
     * StoreSubjectRequest/SubjectPolicy::createOfCategory() — this
     * request only confirms the user may reach the Import action at
     * all (SubjectPolicy::create()), checked via authorize() in the
     * controller before parsing begins.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Plain CSV only for now — no spreadsheet library
            // (Maatwebsite/Excel or similar) is installed in this
            // project, and a hand-rolled .xlsx parser is more risk
            // than this first version needs. Exporting a Google
            // Sheet/Excel file to CSV before uploading is a one-click
            // step for the adviser's own curriculum spreadsheets.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Please upload a .csv file (in Excel: File -> Save As -> CSV, or File -> Download -> CSV in Google Sheets).',
            'file.max' => 'The file is too large — please split it into smaller batches (max 5MB).',
        ];
    }
}