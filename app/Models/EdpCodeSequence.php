<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A running counter for one (Major, Academic Year, Semester) scope,
 * used exclusively by EDPCodeService to hand out the next EDP Code
 * sequence number. Not meant to be queried or edited directly
 * elsewhere — see EDPCodeService for how it's incremented safely
 * under concurrent saves.
 */
class EdpCodeSequence extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'major_id',
        'academic_year',
        'semester_code',
        'last_sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_sequence' => 'integer',
        ];
    }
}