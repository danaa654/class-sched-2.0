<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCHEDULING NOTIFICATION SYSTEM.
 *
 * A single "informational" event for one recipient. Always created
 * from NotificationService, always inside the same DB transaction as
 * the backend operation it reports on — never created directly from
 * a controller or the frontend. See NotificationService's docblock.
 */
class Notification extends Model
{
    /**
     * Deliberately NOT mass-assignable in the usual sense — every row
     * is written via NotificationService::create(), which passes a
     * fully-formed array. $fillable still lists every column so that
     * single controlled write path can use create()/insert() directly
     * without forceFill() gymnastics.
     *
     * @var list<string>
     */
    protected $fillable = [
        'recipient_user_id',
        'type',
        'priority',
        'title',
        'message',
        'data',
        'section_id',
        'section_subject_id',
        'actor_user_id',
        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function sectionSubject(): BelongsTo
    {
        return $this->belongsTo(SectionSubject::class, 'section_subject_id');
    }

    /**
     * Mark this notification read. No-op (no extra write) if it's
     * already read, so mark-all-read style bulk calls stay cheap.
     */
    public function markRead(): void
    {
        if ($this->is_read) {
            return;
        }

        $this->forceFill([
            'is_read' => true,
            'read_at' => now(),
        ])->save();
    }
}