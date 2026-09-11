<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectMembershipRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Changes only the requester's role on an existing Project Membership
 * (e.g. promoting a member to project_lead). Staff eligibility is
 * intentionally not re-checked here — it applies to new assignments only
 * (docs/phases/V1_PHASE_10_DEFINITION.md); an existing membership is left
 * untouched if the staff member's employment status later changes.
 */
class UpdateProjectMembershipRequest extends FormRequest
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
            'role' => ['required', new Enum(ProjectMembershipRole::class)],
        ];
    }
}
