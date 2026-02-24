<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DashboardWidget extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'widget_key',
        'x',
        'y',
        'w',
        'h',
        'sort_order',
        'enabled',
        'config_json',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'config_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (DashboardWidget $widget): void {
            if (!is_string($widget->uuid) || trim($widget->uuid) === '') {
                $widget->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
