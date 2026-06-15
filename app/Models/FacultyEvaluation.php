<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacultyEvaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'instructor',
        'course_code',
        'course_title',
        'term',
        'ratings',
        'comments',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'ratings' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
