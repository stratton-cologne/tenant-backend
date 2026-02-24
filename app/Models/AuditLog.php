<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    protected $fillable = ['uuid', 'user_uuid', 'action', 'meta_json'];

    protected $casts = [
        'meta_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuditLog $auditLog): void {
            if (!is_string($auditLog->uuid) || trim($auditLog->uuid) === '') {
                $auditLog->uuid = (string) Str::uuid();
            }
        });
    }
}
