<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TenantNotification extends Model
{
    protected $table = 'tenant_notifications';

    protected $fillable = [
        'uuid',
        'user_uuid',
        'type',
        'title',
        'message',
        'meta_json',
        'is_read',
        'read_at',
        'is_archived',
        'archived_at',
    ];

    protected $casts = [
        'meta_json' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (TenantNotification $notification): void {
            if (!is_string($notification->uuid) || trim($notification->uuid) === '') {
                $notification->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }
}
