<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Foundation\Http\FormRequest;

class TicketStatusUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = (array) $this->attributes->get('permissions', []);
        return in_array('tickets.status.update', $permissions, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:new,triage,in_progress,waiting,resolved,closed'],
        ];
    }
}
