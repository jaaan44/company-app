<?php

namespace App\Http\Requests\Clients;

use App\Enums\ContactStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreContactRequest extends FormRequest
{
    /**
     * Permission enforcement happens at the route level
     * (`can:clients.manage`), which runs before this request is
     * resolved — mirroring the existing Staff/Organization Form Requests.
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
            'client_id' => ['required', 'string', Rule::exists('clients', 'public_id')],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_primary' => ['sometimes', 'boolean'],
            'status' => ['sometimes', new Enum(ContactStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
