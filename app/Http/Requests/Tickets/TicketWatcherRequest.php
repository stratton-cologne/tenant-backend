<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Foundation\Http\FormRequest;

class TicketWatcherRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = (array) $this->attributes->get('permissions', []);
        return in_array('tickets.read', $permissions, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_uuid' => ['nullable', 'uuid'],
            'email' => ['nullable', 'email:rfc'],
            'mode' => ['required', 'in:access_notify,notify_only'],
        ];
    }
}
