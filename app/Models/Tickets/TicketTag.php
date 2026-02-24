<?php

namespace App\Models\Tickets;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class TicketTag extends Model
{
    use HasUuid;

    protected $fillable = ['uuid', 'tenant_id', 'name', 'slug', 'color'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
