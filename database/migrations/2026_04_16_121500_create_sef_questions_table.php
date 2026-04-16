<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sef_questions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('section', 120);
            $table->unsignedTinyInteger('item_number');
            $table->text('question_text');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['section', 'item_number']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sef_questions');
    }
};
