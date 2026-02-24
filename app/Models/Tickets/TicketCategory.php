<?php

namespace App\Models\Tickets;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class TicketCategory extends Model
{
    use HasUuid;

    protected $fillable = ['uuid', 'tenant_id', 'name', 'slug', 'description', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
