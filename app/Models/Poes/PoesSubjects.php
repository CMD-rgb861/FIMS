<?php

namespace App\Models\Poes;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PoesSubjects extends Model
{
    use HasFactory;

    protected $connection = 'poes';
    protected $table = 'enrollment_courses';
}
