<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supervisor_evaluation_submissions', function (Blueprint $table) {
            // Drop the JSON ratings column
            $table->dropColumn('ratings');
            
            // Add new computed columns
            $table->unsignedSmallInteger('total_score')->default(0)->after('term');
            $table->unsignedSmallInteger('max_score')->default(0)->after('total_score');
            $table->decimal('rating_percentage', 5, 2)->default(0.00)->after('max_score');
            
            // Add status column
            $table->string('status')->default('submitted')->after('submitted_at');
            
            // Add index for status
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('supervisor_evaluation_submissions', function (Blueprint $table) {
            $table->json('ratings')->nullable();
            $table->dropColumn(['total_score', 'max_score', 'rating_percentage', 'status']);
            $table->dropIndex(['status']);
        });
    }
};