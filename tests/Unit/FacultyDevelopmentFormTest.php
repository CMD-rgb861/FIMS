<?php

namespace Tests\Unit;

use App\Models\FacultyDevelopmentForm;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FacultyDevelopmentFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('faculty_development_forms');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->integer('id_no')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('faculty_development_forms', function (Blueprint $table): void {
            $table->id();
            $table->integer('id_no');
            $table->unsignedBigInteger('term_id');
            $table->text('areas_for_improvement')->nullable();
            $table->text('proposed_learning_and_development_activities')->nullable();
            $table->text('action_plan')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['id_no', 'term_id']);
        });
    }

    public function test_it_detects_a_submitted_feda_form_for_the_faculty_and_term(): void
    {
        FacultyDevelopmentForm::create([
            'id_no' => 1001,
            'term_id' => 77,
            'submitted_at' => now(),
            'submitted_by' => 1,
        ]);

        $this->assertTrue(FacultyDevelopmentForm::hasSubmittedFormFor(1001, 77));
        $this->assertFalse(FacultyDevelopmentForm::hasSubmittedFormFor(1001, 78));
        $this->assertFalse(FacultyDevelopmentForm::hasSubmittedFormFor(1002, 77));
    }
}
