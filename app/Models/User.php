<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    protected $fillable = [
        'first_name',
        'last_name',
        'uuid',
        'email',
        'password',
        'mfa_type',
        'mfa_secret',
        'mfa_app_setup_pending',
        'must_change_password',
        'notification_sound_enabled',
        'notification_desktop_enabled',
        'temp_password_expires_at',
    ];

    protected $hidden = [
        'password',
        'mfa_secret',
    ];

    protected $casts = [
        'must_change_password' => 'boolean',
        'mfa_app_setup_pending' => 'boolean',
        'notification_sound_enabled' => 'boolean',
        'notification_desktop_enabled' => 'boolean',
        'temp_password_expires_at' => 'datetime',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role');
    }

    public function moduleEntitlements(): HasMany
    {
        return $this->hasMany(UserModuleEntitlement::class, 'user_uuid', 'uuid');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(TenantNotification::class, 'user_uuid', 'uuid');
    }

    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class, 'user_uuid', 'uuid');
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (!is_string($user->uuid) || trim($user->uuid) === '') {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<int, string>
     */
    public function permissionNames(): array
    {
        return $this->roles()
            ->with('permissions')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }

    public function getFullNameAttribute(): string
    {
        return trim((string) $this->first_name.' '.(string) $this->last_name);
    }

    public function getNameAttribute(): string
    {
        return $this->full_name;
    }
}
