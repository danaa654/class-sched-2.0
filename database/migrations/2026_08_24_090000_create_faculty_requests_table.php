<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faculty Management Requests (spec: "Faculty Management permission
 * and request workflow").
 *
 * Problem this closes: Dean/OIC/Assistant Dean could previously
 * create an ACTIVE Faculty record directly (FacultyPolicy::create())
 * and delete/deactivate one directly (FacultyPolicy::delete()) for
 * their own College, with no institution-wide review step — a gap
 * once Faculty rows feed directly into Auto Schedule eligibility.
 *
 * New model, mirroring faculty_load_requests: only Administrator/
 * Registrar may create or deactivate a Faculty record directly.
 * Dean/OIC/Assistant Dean instead submit a request here — 'Creation'
 * (faculty_id null until approved, full proposed record kept in
 * `payload`) or 'Deletion' (faculty_id set, `affected_summary`
 * snapshots active assignments at submission time so a reviewer sees
 * the impact without re-querying). Administrator/Registrar review and
 * Approve/Reject; only approval mutates the Faculty table — see
 * FacultyRequestController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_requests', function (Blueprint $table) {
            $table->id();

            $table->enum('request_type', ['Creation', 'Deletion']);

            // Null for a Creation request until it is approved and the
            // Faculty record actually exists; always set for a
            // Deletion request (it targets an existing Faculty).
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->nullOnDelete();

            // The College this request is scoped/targeted to (null =
            // General Education/Minor, Assistant Dean's lane) — for a
            // Creation request this is the PROPOSED college, re-derived
            // from the requester's own college_id server-side, never
            // trusted from the payload. See FacultyRequestPolicy and
            // FacultyRequestController::storeCreation().
            $table->foreignId('college_id')->nullable()->constrained('colleges')->nullOnDelete();

            // Creation: the full proposed Faculty attributes (name,
            // faculty_id, employment_type, department/program, rank,
            // contact info, teaching/subject qualifications,
            // availability, proposed workload, etc.) — validated
            // again in full before the Faculty row is actually
            // created on approval. Null for Deletion requests.
            $table->json('payload')->nullable();

            // Deletion: a snapshot of the Faculty's active
            // assignments at submission time (subject/section counts,
            // weekly hours, affected subject/section codes, whether
            // any assignment sits in a finalized/locked Section) — so
            // Admin/Registrar see the impact in the review queue
            // without re-deriving it, and so the warning shown to the
            // requester and the one shown to the reviewer can never
            // disagree. Recomputed and re-validated again at review
            // time regardless (never trusted as still-current) — see
            // FacultyRequestController::review().
            $table->json('affected_summary')->nullable();

            // Required justification/remarks from the requester.
            $table->text('reason');

            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');

            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['status']);
            $table->index(['request_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_requests');
    }
};