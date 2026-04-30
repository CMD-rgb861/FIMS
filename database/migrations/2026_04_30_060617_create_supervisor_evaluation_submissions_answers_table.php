<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisor_evaluation_submissions_answers', function (Blueprint $table) {
            $table->id();

            // Link to submission
            $table->foreignId('supervisor_evaluation_submission_id')
                  ->constrained('supervisor_evaluation_submissions')
                  ->cascadeOnDelete();

            // Question reference
            $table->foreignId('sef_question_id')
                  ->constrained('sef_questions')
                  ->cascadeOnDelete();

            // Rating per question
            $table->unsignedTinyInteger('rating');

            $table->timestamps();

            // Prevent duplicate answers per question per submission
            $table->unique(
                ['supervisor_evaluation_submission_id', 'sef_question_id'],
                'sesa_submission_question_unique'
            );

            // Indexes
            $table->index('sef_question_id');
            $table->index('rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_evaluation_submissions_answers');
    }
};