<?php

namespace App\Models\Tickets;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketAttachment extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'ticket_id',
        'uploaded_by_user_id',
        'file_name',
        'mime_type',
        'size_bytes',
        'current_version',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'current_version' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TicketAttachmentVersion::class, 'attachment_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
