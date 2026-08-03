<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacultyDevelopmentForm extends Model
{
    protected $table = 'faculty_development_forms';

    protected $fillable = [
        'id_no',
        'term_id',
        'areas_for_improvement',
        'proposed_learning_and_development_activities',
        'action_plan',
        'submitted_at',
        'submitted_by',
        'updated_by',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    /**
     * Get the faculty user for this development form.
     */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_no', 'id_no');
    }

    /**
     * Get the user who submitted this form.
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Get the user who last updated this form.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include forms for a specific faculty.
     */
    public function scopeForFaculty($query, $idNo)
    {
        return $query->where('id_no', $idNo);
    }

    /**
     * Scope a query to only include forms for a specific term.
     */
    public function scopeForTerm($query, $termId)
    {
        return $query->where('term_id', $termId);
    }

    /**
     * Check if the form has been submitted.
     */
    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /**
     * Determine whether a faculty has a submitted FEDA form for a given term.
     */
    public static function hasSubmittedFormFor($idNo, $termId): bool
    {
        if ($idNo === null || $termId === null || $termId === '') {
            return false;
        }

        return self::query()
            ->where('id_no', $idNo)
            ->where('term_id', $termId)
            ->whereNotNull('submitted_at')
            ->exists();
    }

    /**
     * Mark the form as submitted.
     */
    public function markAsSubmitted(int $userId): void
    {
        $this->submitted_at = now();
        $this->submitted_by = $userId;
        $this->save();
    }
}