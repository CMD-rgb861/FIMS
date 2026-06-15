<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_head_grades', function (Blueprint $table) {

            // Add integer term_id
            if (!Schema::hasColumn('unit_head_grades', 'term_id')) {

                // Use local integer term_id to reference
                // external school_years.id value.
                $table->integer('term_id')
                    ->nullable()
                    ->after('term');
            }

            // Drop old varchar term column
            if (Schema::hasColumn('unit_head_grades', 'term')) {
                $table->dropColumn('term');
            }

            // Add index for performance
            $table->index('term_id');
        });
    }

    public function down(): void
    {
        Schema::table('unit_head_grades', function (Blueprint $table) {

            // Remove index
            $table->dropIndex(['term_id']);

            // Drop term_id
            if (Schema::hasColumn('unit_head_grades', 'term_id')) {
                $table->dropColumn('term_id');
            }

            // Restore old term column
            if (!Schema::hasColumn('unit_head_grades', 'term')) {
                $table->string('term')
                    ->nullable()
                    ->after('course_code');
            }
        });
    }
};