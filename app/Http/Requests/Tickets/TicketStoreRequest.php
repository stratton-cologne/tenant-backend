<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Foundation\Http\FormRequest;

class TicketStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = (array) $this->attributes->get('permissions', []);
        return in_array('tickets.create', $permissions, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'in:low,medium,high,urgent'],
            'queue_uuid' => ['nullable', 'uuid'],
            'type_uuid' => ['nullable', 'uuid'],
            'category_uuid' => ['nullable', 'uuid'],
            'tag_uuids' => ['nullable', 'array'],
            'tag_uuids.*' => ['uuid'],
        ];
    }
}
