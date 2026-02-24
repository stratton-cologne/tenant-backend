<?php

namespace App\Models\Tickets;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class TicketSlaPolicy extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'priority',
        'first_response_minutes',
        'resolve_minutes',
        'is_active',
    ];

    protected $casts = [
        'first_response_minutes' => 'integer',
        'resolve_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
