<?php

namespace App\Policies\Tickets;

use App\Models\Tickets\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * @param array<int, string> $permissions
     */
    public function viewAny(User $user, array $permissions): bool
    {
        return in_array('tickets.read', $permissions, true);
    }

    /**
     * @param array<int, string> $permissions
     */
    public function view(User $user, Ticket $ticket, array $permissions): bool
    {
        return in_array('tickets.read', $permissions, true);
    }

    /**
     * @param array<int, string> $permissions
     */
    public function create(User $user, array $permissions): bool
    {
        return in_array('tickets.create', $permissions, true);
    }

    /**
     * @param array<int, string> $permissions
     */
    public function update(User $user, Ticket $ticket, array $permissions): bool
    {
        return in_array('tickets.update', $permissions, true);
    }

    /**
     * @param array<int, string> $permissions
     */
    public function delete(User $user, Ticket $ticket, array $permissions): bool
    {
        return in_array('tickets.delete', $permissions, true);
    }

    /**
     * @param array<int, string> $permissions
     */
    public function assign(User $user, Ticket $ticket, array $permissions): bool
    {
        return in_array('tickets.assign', $permissions, true);
    }

    /**
     * @param array<int, string> $permissions
     */
    public function updateStatus(User $user, Ticket $ticket, array $permissions): bool
    {
        return in_array('tickets.status.update', $permissions, true);
    }
}
