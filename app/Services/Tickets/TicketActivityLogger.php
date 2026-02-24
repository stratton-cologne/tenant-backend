<?php

namespace App\Services\Tickets;

use App\Models\AuditLog;
use App\Models\User;

class TicketActivityLogger
{
    /**
     * @param array<string, mixed> $meta
     */
    public function log(?User $user, string $action, array $meta = []): void
    {
        AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'meta_json' => $meta,
        ]);
    }
}
