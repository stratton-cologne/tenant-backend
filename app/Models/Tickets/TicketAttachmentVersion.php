<?php

namespace App\Models\Tickets;

use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAttachmentVersion extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'attachment_id',
        'version_no',
        'storage_disk',
        'storage_path',
        'file_name',
        'mime_type',
        'size_bytes',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'version_no' => 'integer',
        'size_bytes' => 'integer',
    ];

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(TicketAttachment::class, 'attachment_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
