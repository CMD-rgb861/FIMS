<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supervisor_evaluation_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('instructor');
            $table->string('course_code', 100);
            $table->string('course_title');
            $table->string('term');
            $table->json('ratings');
            $table->text('comments')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['user_id', 'instructor']);
            $table->index(['instructor', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_evaluation_submissions');
    }
};
