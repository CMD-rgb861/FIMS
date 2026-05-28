<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisor_evaluation_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')  // ✅ Correct: submission_id (singular)
                  ->constrained('supervisor_evaluation_submissions')
                  ->cascadeOnDelete();
            $table->string('question_key', 50);  // e.g., "q1", "teaching_quality", or FK to sef_questions
            $table->unsignedTinyInteger('score'); // 1-5
            $table->timestamps();

            // Unique constraint to prevent duplicate answers per question per submission
            $table->unique(['submission_id', 'question_key'], 'sup_eval_submission_question_unique');
            
            // Index for faster lookups by question
            $table->index('question_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_evaluation_answers');
    }
};