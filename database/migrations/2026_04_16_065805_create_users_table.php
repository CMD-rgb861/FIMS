<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id(); // bigint unsigned auto_increment

            $table->string('id_no')->unique();

            $table->string('lastname');
            $table->string('firstname');
            $table->string('middlename')->nullable();
            $table->string('extname')->nullable();

            $table->string('academic_rank')->nullable();
            $table->string('password')->nullable();

            // Relationships
            $table->foreignId('unit_id')
                  ->nullable()
                  ->constrained('units')
                  ->nullOnDelete();

            $table->foreignId('college_id')
                  ->nullable()
                  ->constrained('colleges')
                  ->nullOnDelete();

            $table->timestamps();

            // Indexes (Laravel already indexes foreignId, but keeping explicit if needed)
            $table->index('unit_id');
            $table->index('college_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
