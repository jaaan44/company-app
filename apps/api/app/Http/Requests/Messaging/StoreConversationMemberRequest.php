<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adds a member to a group conversation. Ownership/type checks
 * (only the group owner may add a member; only group conversations
 * support this at all) happen in ConversationMemberController, not here.
 */
class StoreConversationMemberRequest extends FormRequest
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
            'staff_id' => ['required', 'string', Rule::exists('staff', 'public_id')],
        ];
    }
}
