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
        'instructor',
        'course_code',
        'course_title',
        'term',
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
            'instructor' => $payload['instructor'],
            'course_code' => $payload['course_code'],
            'course_title' => $payload['course_title'] ?? '',
            'term' => $payload['term'],
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