<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'college_id',
        'code',
        'name',
        'short_name',
        'description',
        'status',
    ];

    /**
     * The college this department belongs to.
     *
     * @return BelongsTo<College, Department>
     */
    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * The majors that belong to this department.
     *
     * @return HasMany<Major>
     */
    public function majors(): HasMany
    {
        return $this->hasMany(Major::class);
    }

    /**
     * The users assigned to this department.
     *
     * @return HasMany<User>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}