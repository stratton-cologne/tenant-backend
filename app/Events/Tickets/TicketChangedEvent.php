<?php

namespace App\Events\Tickets;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketChangedEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $ticketUuid,
        public readonly string $eventName,
        public readonly array $payload = [],
    )
    {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('tenant.'.$this->tenantId.'.tickets'),
            new Channel('tenant.'.$this->tenantId.'.ticket.'.$this->ticketUuid),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload + [
            'ticket_uuid' => $this->ticketUuid,
            'tenant_id' => $this->tenantId,
        ];
    }
}
