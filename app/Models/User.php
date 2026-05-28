<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'id_no',
        'lastname',
        'firstname',
        'middlename',
        'extname',
        'password',
        'unit_id',
        'college_id',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /*
    |----------------------------------------
    | RELATIONSHIPS
    |----------------------------------------
    */

    public function personalInformation(): HasOne
    {
        return $this->hasOne(PersonalInformation::class);
    }

    public function unitHead(): HasOne
    {
        return $this->hasOne(UnitHead::class);
    }

    public function dean(): HasOne
    {
        return $this->hasOne(Dean::class);
    }

    public function associateDean(): HasOne
    {
        return $this->hasOne(AssociateDean::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /*
    |----------------------------------------
    | ROLE RESOLUTION (DYNAMIC)
    |----------------------------------------
    */

    public function resolveRole(): string
    {
        if ($this->isAdmin()) {
            return 'admin';
        }

        if ($this->dean()->exists()) {
            return 'dean';
        }

        if ($this->associateDean()->exists()) {
            return 'associate_dean';
        }

        if ($this->unitHead()->exists()) {
            return 'unit_head';
        }

        return 'faculty';
    }

    public function getRoleAttribute(): string
    {
        return $this->resolveRole();
    }

    /*
    |----------------------------------------
    | ROLE CHECK HELPERS
    |----------------------------------------
    */

    public function isAdmin(): bool
    {
        // Prefer explicit database flag when present
        if (isset($this->is_admin)) {
            return (bool) $this->is_admin;
        }

        $idNo = strtolower(trim((string) $this->id_no));

        return $idNo === 'admin' || str_starts_with($idNo, 'admin-');
    }

    public function isDean(): bool
    {
        return $this->dean()->exists();
    }

    public function isAssociateDean(): bool
    {
        return $this->associateDean()->exists();
    }

    public function isUnitHead(): bool
    {
        return $this->unitHead()->exists();
    }
    
    public function isFaculty(): bool
    {
        // A user is faculty if they are NOT admin, NOT dean, NOT associate dean, and NOT unit head
        return !$this->isAdmin() && !$this->isDean() && !$this->isAssociateDean() && !$this->isUnitHead();
    }

    public function canEvaluateFaculty(): bool
    {
        return $this->isAdmin() || $this->isDean() || $this->isAssociateDean() || $this->isUnitHead();
    }
}