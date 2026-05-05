<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('unit_head_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('instructor');
            $table->string('course_code', 100);
            $table->string('course_title');
            $table->string('term');
            $table->decimal('grade', 4, 2);
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['user_id', 'instructor', 'course_code']);
            $table->index(['instructor', 'course_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_head_grades');
    }
};
