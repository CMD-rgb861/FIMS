<?php

namespace App\Models\Poes;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PoesEvalAnswers extends Model
{
    use HasFactory;

    protected $connection = 'lnu_poes';
    protected $table = 'student_evaluation_submission_answers';
}
