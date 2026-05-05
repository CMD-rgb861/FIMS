<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisor_evaluation_submissions', function (Blueprint $table) {
            $table->id();

            // Who submitted
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // Evaluated instructor & course info
            $table->string('instructor', 255)->index();
            $table->string('course_code', 100);
            $table->string('course_title');
            $table->string('term');

            // Ratings data
            $table->json('ratings')->nullable();

            // Optional overall feedback
            $table->text('comments')->nullable();

            // Submission metadata
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            // Indexing for reports
            $table->index(['instructor', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_evaluation_submissions');
    }
};