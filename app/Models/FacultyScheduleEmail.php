<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery attempt of a Faculty Schedule email (spec sections 10-12).
 * Read/write only through FacultyScheduleEmailService — never sent
 * automatically on schedule edits (spec section 13).
 */
class FacultyScheduleEmail extends Model
{
    protected $fillable = [
        'faculty_id',
        'academic_term_id',
        'sent_by',
        'recipient_email',
        'schedule_version',
        'email_type',
        'status',
        'error_message',
        'schedule_snapshot',
        'pdf_filename',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'schedule_snapshot' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function markSent(): void
    {
        $this->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'error_message' => null,
        ])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => 'failed',
            'error_message' => $reason,
        ])->save();
    }
}