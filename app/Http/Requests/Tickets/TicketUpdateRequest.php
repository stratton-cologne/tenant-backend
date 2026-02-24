<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Foundation\Http\FormRequest;

class TicketUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = (array) $this->attributes->get('permissions', []);
        return in_array('tickets.update', $permissions, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'priority' => ['sometimes', 'in:low,medium,high,urgent'],
            'queue_uuid' => ['nullable', 'uuid'],
            'type_uuid' => ['nullable', 'uuid'],
            'category_uuid' => ['nullable', 'uuid'],
            'tag_uuids' => ['nullable', 'array'],
            'tag_uuids.*' => ['uuid'],
        ];
    }
}
