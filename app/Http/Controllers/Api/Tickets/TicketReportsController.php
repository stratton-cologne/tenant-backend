<?php

namespace App\Http\Controllers\Api\Tickets;

use App\Http\Controllers\Api\Tickets\Concerns\ResolvesTicketContext;
use App\Http\Controllers\Controller;
use App\Models\Tickets\Ticket;
use App\Services\Tickets\TicketSlaService;
use App\Services\Tickets\TicketTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketReportsController extends Controller
{
    use ResolvesTicketContext;

    public function slaBreaches(Request $request, TicketTenantContext $tenantContext, TicketSlaService $slaService): JsonResponse
    {
        abort_unless(in_array('tickets.report.read', $this->permissions($request), true), 403, 'Missing permission: tickets.report.read');
        $tenantId = $this->ticketTenantId($tenantContext);

        $rows = Ticket::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['closed'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (Ticket $ticket) use ($slaService, $tenantId): ?array {
                $sla = $slaService->evaluate($ticket, $tenantId);
                if (!$sla['first_response_breached'] && !$sla['resolve_breached']) {
                    return null;
                }
                return [
                    'ticket_uuid' => (string) $ticket->uuid,
                    'ticket_no' => (string) $ticket->ticket_no,
                    'title' => (string) $ticket->title,
                    'status' => (string) $ticket->status,
                    'priority' => (string) $ticket->priority,
                    'first_response_breached' => (bool) $sla['first_response_breached'],
                    'resolve_breached' => (bool) $sla['resolve_breached'],
                    'first_response_due_at' => $sla['first_response_due_at'],
                    'resolve_due_at' => $sla['resolve_due_at'],
                ];
            })
            ->filter()
            ->values();

        return response()->json(['data' => $rows]);
    }
}
