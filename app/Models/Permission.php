<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Permission extends Model
{
    protected $fillable = ['uuid', 'name'];

    protected static function booted(): void
    {
        static::creating(function (Permission $permission): void {
            if (!is_string($permission->uuid) || trim($permission->uuid) === '') {
                $permission->uuid = (string) Str::uuid();
            }
        });
    }
}
