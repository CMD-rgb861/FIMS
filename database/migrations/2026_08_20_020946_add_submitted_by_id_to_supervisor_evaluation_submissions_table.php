<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supervisor_evaluation_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('submitted_by_id')
                  ->nullable()
                  ->index()
                  ->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('supervisor_evaluation_submissions', function (Blueprint $table) {
            $table->dropColumn('submitted_by_id');
        });
    }
};