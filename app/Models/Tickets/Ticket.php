<?php

namespace App\Models\Tickets;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'ticket_no',
        'title',
        'description',
        'status',
        'priority',
        'reporter_user_id',
        'assignee_user_id',
        'queue_id',
        'type_id',
        'category_id',
        'first_response_at',
        'resolved_at',
        'waiting_started_at',
        'waiting_total_seconds',
        'last_commented_at',
    ];

    protected $casts = [
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'waiting_started_at' => 'datetime',
        'last_commented_at' => 'datetime',
        'waiting_total_seconds' => 'integer',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(TicketQueue::class, 'queue_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'type_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(TicketTag::class, 'ticket_ticket_tag');
    }

    public function watchers(): HasMany
    {
        return $this->hasMany(TicketWatcher::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
