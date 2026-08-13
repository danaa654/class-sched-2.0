<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single system-wide configuration row (e.g. "general.school_name").
 *
 * Rows are read/written exclusively through App\Services\SettingsService
 * — nothing else should query this model directly, so every value
 * stays cached and validated consistently. See SettingsService for the
 * full list of recognised keys, their groups, and their defaults.
 */
class SystemSetting extends Model
{
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'group',
        'value',
        'updated_at',
        'updated_by',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    /**
     * The user who last changed this setting.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}