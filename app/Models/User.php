<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['id_no', 'lastname', 'firstname', 'middlename', 'extname', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_UNIT_HEAD = 'unit_head';
    public const ROLE_FACULTY = 'faculty';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function personalInformation(): HasOne
    {
        return $this->hasOne(PersonalInformation::class);
    }

    public function unitHead(): HasOne
    {
        return $this->hasOne(UnitHead::class);
    }

    public function resolveRole(): string
    {
        $storedRole = $this->normalizedStoredRole();

        if ($storedRole !== null) {
            return $storedRole;
        }

        if ($this->isAdminByIdNo()) {
            return self::ROLE_ADMIN;
        }

        return $this->isUnitHeadByAssignment()
            ? self::ROLE_UNIT_HEAD
            : self::ROLE_FACULTY;
    }

    public function isAdmin(): bool
    {
        $storedRole = $this->normalizedStoredRole();

        if ($storedRole !== null) {
            return $storedRole === self::ROLE_ADMIN;
        }

        return $this->isAdminByIdNo();
    }

    public function isUnitHead(): bool
    {
        $storedRole = $this->normalizedStoredRole();

        if ($storedRole !== null) {
            return $storedRole === self::ROLE_UNIT_HEAD;
        }

        return $this->isUnitHeadByAssignment();
    }

    public function canEvaluateFaculty(): bool
    {
        return $this->isUnitHead();
    }

    private function normalizedStoredRole(): ?string
    {
        $role = strtolower(trim((string) ($this->role ?? '')));

        return in_array($role, [self::ROLE_ADMIN, self::ROLE_UNIT_HEAD, self::ROLE_FACULTY], true)
            ? $role
            : null;
    }

    private function isUnitHeadByAssignment(): bool
    {
        $normalizedIdNo = strtolower(trim((string) $this->id_no));

        if (str_starts_with($normalizedIdNo, 'uh-')) {
            return true;
        }

        if ($normalizedIdNo === 'it-faculty') {
            return false;
        }

        return DB::table('unit_heads')
            ->where('user_id', $this->id)
            ->exists();
    }

    private function isAdminByIdNo(): bool
    {
        $normalizedIdNo = strtolower(trim((string) $this->id_no));

        return $normalizedIdNo === 'admin' || str_starts_with($normalizedIdNo, 'admin-');
    }
}
