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

            // Unit head / instructor reference
            $table->foreignId('head_id')
                  ->constrained('unit_heads') // adjust if table name differs
                  ->cascadeOnDelete();

            // Course info
            $table->string('course_code', 100);
            $table->string('course_title');

            // Academic period
            $table->foreignId('school_year_id')
                  ->constrained('school_years') // make sure this table exists
                  ->cascadeOnDelete();

            $table->string('term');

            // Feedback
            $table->text('comments')->nullable();

            // Timestamp of submission
            $table->timestamp('submitted_at');

            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('term');
            $table->index('school_year_id');
            $table->index('head_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_evaluation_submissions');
    }
};