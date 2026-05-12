<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitHeadGrade extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'instructor',
        'course_code',
        'course_title',
        'term',
        'grade',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'grade' => 'decimal:2',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
