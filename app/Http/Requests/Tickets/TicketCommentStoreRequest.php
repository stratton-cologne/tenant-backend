<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Foundation\Http\FormRequest;

class TicketCommentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = (array) $this->attributes->get('permissions', []);
        return in_array('tickets.comment.create', $permissions, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string'],
            'is_public' => ['sometimes', 'boolean'],
            'source' => ['sometimes', 'in:web,email'],
        ];
    }
}
