<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class College extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'shorten',
    ];

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'department_id');
    }

    public function deans(): HasMany
    {
        return $this->hasMany(Dean::class);
    }

    public function associateDeans(): HasMany
    {
        return $this->hasMany(AssociateDean::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}