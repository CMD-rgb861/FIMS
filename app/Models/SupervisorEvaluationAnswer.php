<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupervisorEvaluationAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'question_key',
        'score',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(SupervisorEvaluationSubmission::class, 'submission_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(SefQuestion::class, 'question_code', 'code');
    }
}