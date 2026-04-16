<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('personal_informations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('dob')->nullable();
            $table->enum('sex', ['male', 'female'])->nullable();
            $table->string('civil_status')->nullable();
            $table->string('email')->unique();
            $table->string('contact_no')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('personal_informations');
    }
};