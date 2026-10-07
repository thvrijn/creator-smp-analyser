<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** The only admin, now and later: only this account can create and remove other accounts. */
    public const ADMIN_EMAIL = 'thvrijn2002@gmail.com';

    protected $fillable = ['username', 'name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    public function isAdmin(): bool
    {
        return $this->email !== null && Str::lower($this->email) === self::ADMIN_EMAIL;
    }

    /** Stored lowercase, so "Thoompje" and "thoompje" are the same login. */
    protected function username(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : Str::lower(trim($value)));
    }
}
