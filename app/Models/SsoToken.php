<?php
// app/Models/SsoToken.php (FIMS App)

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SsoToken extends Model
{
    protected $fillable = [
        'token',
        'id_no',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function isValid(): bool
    {
        return $this->expires_at->isFuture();
    }

    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }
}