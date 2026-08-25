<?php

namespace App\Jobs;

use App\Mail\FacultyScheduleMail;
use App\Models\FacultyScheduleEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers a single Faculty Schedule email (spec section 16 — bulk
 * send must never block the request, so both single-send and
 * bulk-send always go through this job, never Mail::send() inline).
 */
class SendFacultyScheduleEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $facultyScheduleEmailId) {}

    public function handle(): void
    {
        $record = FacultyScheduleEmail::find($this->facultyScheduleEmailId);

        if (! $record) {
            return;
        }

        try {
            // Deliberately uses the "gmail" mailer, not the app's
            // default ("smtp" -> Mailtrap sandbox). Faculty schedule
            // emails need to actually reach the faculty member's real
            // inbox; password reset and everything else keep using the
            // default mailer untouched. See config/mail.php.
            Mail::mailer('gmail')->to($record->recipient_email)->send(new FacultyScheduleMail($record));
            $record->markSent();
        } catch (Throwable $e) {
            Log::warning('Faculty schedule email failed', [
                'faculty_schedule_email_id' => $record->id,
                'error' => $e->getMessage(),
            ]);

            $record->markFailed($e->getMessage());
        }
    }

    public function failed(Throwable $e): void
    {
        $record = FacultyScheduleEmail::find($this->facultyScheduleEmailId);
        $record?->markFailed($e->getMessage());
    }
}