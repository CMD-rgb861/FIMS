<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('associate_deans', function (Blueprint $table) {
            $table->id(); // BIGINT UNSIGNED AUTO_INCREMENT

            $table->foreignId('college_id')
                  ->constrained('colleges')
                  ->cascadeOnDelete();

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->timestamps();

            // Optional: one associate dean per college
            // $table->unique('college_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('associate_deans');
    }
};