<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_id',
        'name',
        'shorten',
    ];

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class, 'department_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function unitHeads(): HasMany
    {
        return $this->hasMany(UnitHead::class);
    }

    public function employeeInfos(): HasMany
    {
        return $this->hasMany(EmployeeInfo::class);
    }
}