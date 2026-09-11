<?php

namespace App\Http\Requests\Clients;

use App\Enums\ClientStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreClientRequest extends FormRequest
{
    /**
     * Permission enforcement happens at the route level
     * (`can:clients.manage`), which runs before this request is resolved —
     * mirroring the existing Organization Structure / Staff Form Requests.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_code' => ['nullable', 'string', 'max:50', Rule::unique('clients', 'client_code')],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', new Enum(ClientStatus::class)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255', 'url'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state_province' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
