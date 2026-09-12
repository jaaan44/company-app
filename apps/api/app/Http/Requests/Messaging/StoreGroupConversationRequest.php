<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates an ad-hoc, named group conversation — the authenticated Staff
 * member becomes its single owner (see ConversationController::
 * storeGroup). `member_staff_ids` names the other members to invite; the
 * owner is always included automatically and is silently deduped if
 * also listed here.
 */
class StoreGroupConversationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'member_staff_ids' => ['required', 'array', 'min:1'],
            'member_staff_ids.*' => ['string', Rule::exists('staff', 'public_id')],
        ];
    }
}
