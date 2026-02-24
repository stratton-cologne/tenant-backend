<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TenantSetting extends Model
{
    protected $fillable = ['uuid', 'key', 'value_json'];

    protected $casts = [
        'value_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (TenantSetting $setting): void {
            if (!is_string($setting->uuid) || trim($setting->uuid) === '') {
                $setting->uuid = (string) Str::uuid();
            }
        });
    }
}
