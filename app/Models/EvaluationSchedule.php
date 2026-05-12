<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvaluationSchedule extends Model
{
    use HasFactory;

    protected $connection = 'lnu_poes';

    protected $fillable = [
        'school_year_id',
        'date_from',
        'date_to',
        'date_extension',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'date_extension' => 'date',
        ];
    }
}