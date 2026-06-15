<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupervisorEvaluationSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'instructor_id_no',
        // course fields removed: supervisor evaluations are per-instructor
        'college_id',
        'unit_id',
        'term_id',
        'total_score',
        'max_score',
        'rating_percentage',
        'comments',
        'submitted_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'rating_percentage' => 'decimal:2',
            'total_score' => 'integer',
            'max_score' => 'integer',
            'term_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SupervisorEvaluationAnswer::class, 'submission_id');
    }

    public function instructorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id_no', 'id_no');
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Calculate and save total_score, max_score, and rating_percentage from answers
     */
    public function calculateScores(): void
    {
        $answers = $this->answers;
        
        $totalScore = $answers->sum('score');
        $questionCount = $answers->count();
        $maxScore = $questionCount * 5;
        $ratingPercentage = $maxScore > 0 ? ($totalScore / $maxScore) * 100 : 0;

        $this->update([
            'total_score' => $totalScore,
            'max_score' => $maxScore,
            'rating_percentage' => round($ratingPercentage, 2),
        ]);
    }

    /**
     * Create submission with answers in a transaction
     */
    public static function createWithAnswers(array $payload): self
    {
        $answers = $payload['answers'] ?? [];

        // Calculate totals first
        $totalScore = 0;
        $questionCount = 0;
        
        foreach ($answers as $score) {
            $score = max(1, min(5, (int) $score));
            $totalScore += $score;
            $questionCount++;
        }

        $maxScore = $questionCount * 5;
        $ratingPercentage = $maxScore > 0 ? ($totalScore / $maxScore) * 100 : 0;

        // Create submission
        $submission = self::create([
            'user_id' => $payload['user_id'],
            'instructor_id_no' => $payload['instructor_id_no'] ?? null,
            // course_code/course_title intentionally not stored for supervisor evaluations
            'college_id' => $payload['college_id'] ?? null,
            'unit_id' => $payload['unit_id'] ?? null,
            'term_id' => $payload['term_id'] ?? null,
            'total_score' => $totalScore,
            'max_score' => $maxScore,
            'rating_percentage' => round($ratingPercentage, 2),
            'comments' => $payload['comments'] ?? null,
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);

        // Create answers - fixed: use index for question_key
        $index = 0;
        foreach ($answers as $score) {
            $index++;
            $submission->answers()->create([
                'question_key' => 'q' . $index,
                'score' => max(1, min(5, (int) $score)),
            ]);
        }

        return $submission;
    }
}