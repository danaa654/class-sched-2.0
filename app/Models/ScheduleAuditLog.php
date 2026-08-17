<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCHEDULING NOTIFICATION SYSTEM — audit trail.
 *
 * One row per discrete action or field-level change (see the
 * create_schedule_audit_logs_table migration's docblock for why this
 * is deliberately kept separate from Notification). Written only by
 * NotificationService::audit(), always inside the same DB transaction
 * as the change it records.
 */
class ScheduleAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'section_id',
        'section_subject_id',
        'field',
        'old_value',
        'new_value',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function sectionSubject(): BelongsTo
    {
        return $this->belongsTo(SectionSubject::class, 'section_subject_id');
    }
}