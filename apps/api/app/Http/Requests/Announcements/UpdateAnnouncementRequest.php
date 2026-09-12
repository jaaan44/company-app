<?php

namespace App\Http\Requests\Announcements;

use App\Enums\AnnouncementAudienceType;
use App\Http\Requests\Announcements\Concerns\ValidatesAnnouncementAudience;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Updating an Announcement (Phase 14). Lifecycle restrictions (archived is
 * immutable) are enforced in AnnouncementController::update(), not here —
 * this only validates field shape/coherence. The audience
 * (audience_type/department_ids/team_ids) is only ever touched atomically
 * — see ValidatesAnnouncementAudience.
 */
class UpdateAnnouncementRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'body' => ['sometimes', 'required', 'string', 'max:10000'],
            'audience_type' => ['sometimes', new Enum(AnnouncementAudienceType::class)],
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['string', Rule::exists('departments', 'public_id')],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['string', Rule::exists('teams', 'public_id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateAudienceCoherence($validator, audienceIsAlwaysPresent: false);
    }
}
