<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supervisor_evaluation_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('supervisor_evaluation_submissions', 'instructor_id_no')) {
                // users.id_no is integer in this project, so keep the same type.
                $table->integer('instructor_id_no')->nullable()->after('user_id');
            }

            if (Schema::hasColumn('supervisor_evaluation_submissions', 'instructor')) {
                $table->dropColumn('instructor');
            }

            // Make course fields nullable per latest schema changes.
            $table->string('course_code', 100)->nullable()->change();
            $table->string('course_title')->nullable()->change();

            if (!Schema::hasColumn('supervisor_evaluation_submissions', 'college_id')) {
                $table->foreignId('college_id')
                    ->nullable()
                    ->after('course_title')
                    ->constrained('colleges')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('supervisor_evaluation_submissions', 'unit_id')) {
                $table->foreignId('unit_id')
                    ->nullable()
                    ->after('college_id')
                    ->constrained('units')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('supervisor_evaluation_submissions', 'term_id')) {
                // Use local integer term_id to reference the external school_years.id value.
                $table->integer('term_id')->nullable()->after('course_title');
            }

            if (Schema::hasColumn('supervisor_evaluation_submissions', 'term')) {
                $table->dropColumn('term');
            }

            // Add FK after the column is present.
            $table->foreign('instructor_id_no')
                ->references('id_no')
                ->on('users')
                ->nullOnDelete();

            $table->index('instructor_id_no');
        });
    }

    public function down(): void
    {
        Schema::table('supervisor_evaluation_submissions', function (Blueprint $table) {
            $table->dropForeign(['instructor_id_no']);
            $table->dropIndex(['instructor_id_no']);
            $table->dropColumn('instructor_id_no');

            if (!Schema::hasColumn('supervisor_evaluation_submissions', 'instructor')) {
                $table->string('instructor', 255);
                $table->index(['instructor', 'term']);
            }

            $table->string('course_code', 100)->nullable(false)->change();
            $table->string('course_title')->nullable(false)->change();

            if (Schema::hasColumn('supervisor_evaluation_submissions', 'unit_id')) {
                $table->dropConstrainedForeignId('unit_id');
            }

            if (Schema::hasColumn('supervisor_evaluation_submissions', 'college_id')) {
                $table->dropConstrainedForeignId('college_id');
            }

            if (Schema::hasColumn('supervisor_evaluation_submissions', 'term_id')) {
                $table->dropColumn('term_id');
            }

            if (!Schema::hasColumn('supervisor_evaluation_submissions', 'term')) {
                $table->string('term')->nullable()->after('course_title');
            }
        });
    }
};