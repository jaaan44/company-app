<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opens (finds-or-creates) the one canonical direct conversation for the
 * authenticated Staff member and the given target Staff member — see
 * ConversationController::storeDirect.
 */
class StoreDirectConversationRequest extends FormRequest
{
    /**
     * Domain checks (linked Staff record) happen in the controller.
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
            'staff_id' => ['required', 'string', Rule::exists('staff', 'public_id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $callerStaff = $this->user()?->staff;
            $targetPublicId = $this->input('staff_id');

            if ($callerStaff !== null && is_string($targetPublicId) && $targetPublicId === $callerStaff->public_id) {
                $validator->errors()->add('staff_id', 'You cannot start a direct conversation with yourself.');
            }
        });
    }
}
