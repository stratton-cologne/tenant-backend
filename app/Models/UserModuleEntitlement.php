<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserModuleEntitlement extends Model
{
    protected $fillable = [
        'uuid',
        'user_uuid',
        'module_slug',
        'assigned_by_uuid',
    ];

    protected static function booted(): void
    {
        static::creating(function (UserModuleEntitlement $entitlement): void {
            if (!is_string($entitlement->uuid) || trim($entitlement->uuid) === '') {
                $entitlement->uuid = (string) Str::uuid();
            }
        });
    }
}

