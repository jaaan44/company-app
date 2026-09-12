<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sends a plain-text message into a conversation. Membership
 * authorization happens in MessageController, not here. 4,000 characters
 * is a deliberate bound (see the messages migration) — no rich text, no
 * HTML sanitization, mirroring Announcement's plain-text-body precedent.
 */
class StoreMessageRequest extends FormRequest
{
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
            'body' => ['required', 'string', 'max:4000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $body = $this->input('body');

            if (is_string($body) && trim($body) === '') {
                $validator->errors()->add('body', 'The message cannot be empty.');
            }
        });
    }
}
