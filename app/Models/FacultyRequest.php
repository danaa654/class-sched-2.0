<?php

namespace App\Models;

use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Dean/OIC/Assistant Dean's request to either:
 *  - 'Creation': add a new Faculty member (they may never create an
 *    ACTIVE Faculty record directly — see FacultyPolicy::create()), or
 *  - 'Deletion': permanently remove an existing Faculty member
 *    (they may never delete directly — see FacultyPolicy::delete()).
 *
 * This table is the ONLY path for a College-scoped role to do either.
 * Administrator/Registrar still act directly (FacultyController@store
 * / @destroy) — see FacultyRequestController for the request/approve
 * workflow itself.
 */
class FacultyRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_type',
        'faculty_id',
        'college_id',
        'payload',
        'affected_summary',
        'reason',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'affected_summary' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Faculty, FacultyRequest> */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    /** @return BelongsTo<College, FacultyRequest> */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /** @return BelongsTo<User, FacultyRequest> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, FacultyRequest> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * RBAC query scope, mirroring Faculty::scopeVisibleTo(): a
     * College-scoped Dean/OIC sees only requests targeting their own
     * College (including their own Creation requests, whose
     * college_id is forced to match at submission time — see
     * FacultyRequestController::storeCreation()); Assistant Dean sees
     * only General Education/Minor (college_id null) requests;
     * Admin/Registrar see everything.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<FacultyRequest>  $query
     * @return \Illuminate\Database\Eloquent\Builder<FacultyRequest>
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (AccessScope::isUnrestricted($user)) {
            return $query;
        }

        if (AccessScope::isAssistantDean($user)) {
            return $query->whereNull('college_id');
        }

        $ids = AccessScope::visibleCollegeIds($user);

        return $query->whereIn('college_id', $ids ?? [-1]);
    }
}