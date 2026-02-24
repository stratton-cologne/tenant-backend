<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Foundation\Http\FormRequest;

class TicketSlaPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = (array) $this->attributes->get('permissions', []);
        return in_array('tickets.sla.manage', $permissions, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'priority' => ['required', 'in:low,medium,high,urgent'],
            'first_response_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
            'resolve_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
