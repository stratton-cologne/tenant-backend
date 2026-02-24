<?php

namespace App\Http\Controllers\Api\Tickets;

use App\Http\Controllers\Api\Tickets\Concerns\ResolvesTicketContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\TicketSlaPolicyRequest;
use App\Models\Tickets\TicketSlaPolicy;
use App\Services\Tickets\TicketActivityLogger;
use App\Services\Tickets\TicketTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketSlaPolicyController extends Controller
{
    use ResolvesTicketContext;

    public function index(Request $request, TicketTenantContext $tenantContext): JsonResponse
    {
        abort_unless(in_array('tickets.sla.manage', $this->permissions($request), true), 403, 'Missing permission: tickets.sla.manage');
        $tenantId = $this->ticketTenantId($tenantContext);

        $rows = TicketSlaPolicy::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('priority')
            ->get()
            ->map(fn (TicketSlaPolicy $row): array => [
                'uuid' => (string) $row->uuid,
                'priority' => (string) $row->priority,
                'first_response_minutes' => (int) $row->first_response_minutes,
                'resolve_minutes' => (int) $row->resolve_minutes,
                'is_active' => (bool) $row->is_active,
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function upsert(
        TicketSlaPolicyRequest $request,
        TicketTenantContext $tenantContext,
        TicketActivityLogger $logger
    ): JsonResponse {
        $tenantId = $this->ticketTenantId($tenantContext);
        $payload = $request->validated();

        $row = TicketSlaPolicy::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'priority' => (string) $payload['priority'],
            ],
            [
                'first_response_minutes' => (int) $payload['first_response_minutes'],
                'resolve_minutes' => (int) $payload['resolve_minutes'],
                'is_active' => (bool) ($payload['is_active'] ?? true),
            ]
        );

        $logger->log($this->authUser($request), 'tickets.sla.updated', [
            'priority' => (string) $row->priority,
            'uuid' => (string) $row->uuid,
        ]);

        return response()->json([
            'data' => [
                'uuid' => (string) $row->uuid,
                'priority' => (string) $row->priority,
                'first_response_minutes' => (int) $row->first_response_minutes,
                'resolve_minutes' => (int) $row->resolve_minutes,
                'is_active' => (bool) $row->is_active,
            ],
        ]);
    }
}
