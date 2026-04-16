<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SefQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'section',
        'item_number',
        'question_text',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
