<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ModuleEntitlement extends Model
{
    protected $fillable = ['uuid', 'module_slug', 'active', 'seats', 'source', 'license_key', 'valid_until'];

    protected $casts = [
        'active' => 'boolean',
        'seats' => 'integer',
        'valid_until' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ModuleEntitlement $entitlement): void {
            if (!is_string($entitlement->uuid) || trim($entitlement->uuid) === '') {
                $entitlement->uuid = (string) Str::uuid();
            }
        });
    }
}
