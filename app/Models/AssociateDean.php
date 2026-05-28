<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssociateDean extends Model
{
    protected $fillable = [
        'college_id',
        'user_id',
    ];

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}