<?php

namespace App\Http\Controllers\Api\Tickets\Concerns;

use App\Models\User;
use App\Services\Tickets\TicketTenantContext;
use Illuminate\Http\Request;

trait ResolvesTicketContext
{
    protected function ticketTenantId(TicketTenantContext $tenantContext): string
    {
        return $tenantContext->tenantId();
    }

    protected function authUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->attributes->get('auth.user');
        return $user;
    }

    /**
     * @return array<int, string>
     */
    protected function permissions(Request $request): array
    {
        return (array) $request->attributes->get('permissions', []);
    }
}
