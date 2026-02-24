<?php

namespace App\Services\Tickets;

use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketSlaPolicy;
use Carbon\CarbonImmutable;

class TicketSlaService
{
    public function applyOnStatusChange(Ticket $ticket, string $fromStatus, string $toStatus): void
    {
        if ($fromStatus !== 'waiting' && $toStatus === 'waiting') {
            $ticket->waiting_started_at = now();
            return;
        }

        if ($fromStatus === 'waiting' && $toStatus !== 'waiting' && $ticket->waiting_started_at !== null) {
            $seconds = max(0, now()->diffInSeconds($ticket->waiting_started_at));
            $ticket->waiting_total_seconds = (int) $ticket->waiting_total_seconds + $seconds;
            $ticket->waiting_started_at = null;
        }
    }

    public function markFirstResponseIfNeeded(Ticket $ticket): void
    {
        if ($ticket->first_response_at === null) {
            $ticket->first_response_at = now();
        }
    }

    /**
     * @return array{first_response_minutes:int,resolve_minutes:int}|null
     */
    public function getPolicyForPriority(string $tenantId, string $priority): ?array
    {
        $policy = TicketSlaPolicy::query()
            ->where('tenant_id', $tenantId)
            ->where('priority', $priority)
            ->where('is_active', true)
            ->first();

        if ($policy === null) {
            return null;
        }

        return [
            'first_response_minutes' => (int) $policy->first_response_minutes,
            'resolve_minutes' => (int) $policy->resolve_minutes,
        ];
    }

    /**
     * @return array{first_response_due_at:?string,resolve_due_at:?string,first_response_breached:bool,resolve_breached:bool}
     */
    public function evaluate(Ticket $ticket, string $tenantId): array
    {
        $policy = $this->getPolicyForPriority($tenantId, (string) $ticket->priority);
        if ($policy === null) {
            return [
                'first_response_due_at' => null,
                'resolve_due_at' => null,
                'first_response_breached' => false,
                'resolve_breached' => false,
            ];
        }

        $createdAt = CarbonImmutable::parse($ticket->created_at);
        $firstResponseDue = $createdAt->addMinutes($policy['first_response_minutes']);
        $resolveDue = $createdAt->addMinutes($policy['resolve_minutes'])->addSeconds((int) $ticket->waiting_total_seconds);

        $firstResponseBreached = $ticket->first_response_at === null && now()->greaterThan($firstResponseDue);
        $resolveBreached = !in_array((string) $ticket->status, ['resolved', 'closed'], true) && now()->greaterThan($resolveDue);

        return [
            'first_response_due_at' => $firstResponseDue->toISOString(),
            'resolve_due_at' => $resolveDue->toISOString(),
            'first_response_breached' => $firstResponseBreached,
            'resolve_breached' => $resolveBreached,
        ];
    }
}
