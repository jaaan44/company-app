<?php

namespace App\Http\Requests\Announcements;

use App\Enums\AnnouncementAudienceType;
use App\Http\Requests\Announcements\Concerns\ValidatesAnnouncementAudience;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Creating an Announcement (Phase 14) always produces a 'draft' —
 * publishing is a separate, explicit action
 * (AnnouncementController::publish()). Permission enforcement
 * (`announcements.manage`) happens at the route level, which runs before
 * this request is resolved.
 */
class StoreAnnouncementRequest extends FormRequest
{
    use ValidatesAnnouncementAudience;

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
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'audience_type' => ['sometimes', new Enum(AnnouncementAudienceType::class)],
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['string', Rule::exists('departments', 'public_id')],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['string', Rule::exists('teams', 'public_id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateAudienceCoherence($validator, audienceIsAlwaysPresent: true);
    }
}
