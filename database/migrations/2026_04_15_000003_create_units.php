<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id(); // bigint unsigned auto_increment

            // FK to colleges (your SQL calls it department_id but references colleges)
            $table->foreignId('department_id')
                  ->constrained('colleges')
                  ->cascadeOnDelete();

            $table->string('name');
            $table->string('shorten')->nullable();

            $table->timestamps();

            // Index (optional since foreignId already indexes it)
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};