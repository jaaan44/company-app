<?php

namespace App\Http\Requests\Announcements\Concerns;

use App\Enums\AnnouncementAudienceType;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared by Store/UpdateAnnouncementRequest. A `scoped` announcement must
 * target at least one Department or Team; a `company_wide` announcement
 * must target neither — the audience is always specified atomically (see
 * docs/phases/V1_PHASE_14_DEFINITION.md's Editing the Audience section),
 * never as an incremental patch to an existing target set.
 */
trait ValidatesAnnouncementAudience
{
    /**
     * @param  bool  $audienceIsAlwaysPresent  true on create, where a
     *                                         missing audience_type defaults to CompanyWide (so coherence is
     *                                         always checked); false on update, where the audience is only
     *                                         touched — and therefore only validated — when audience_type is
     *                                         explicitly present in the request.
     */
    protected function validateAudienceCoherence(Validator $validator, bool $audienceIsAlwaysPresent): void
    {
        $validator->after(function (Validator $validator) use ($audienceIsAlwaysPresent): void {
            $hasDepartments = is_array($this->input('department_ids')) && count($this->input('department_ids')) > 0;
            $hasTeams = is_array($this->input('team_ids')) && count($this->input('team_ids')) > 0;

            if (! $audienceIsAlwaysPresent && ! $this->has('audience_type')) {
                // The audience is only ever touched (and therefore only
                // ever validated) atomically, together with audience_type
                // — see docs/phases/V1_PHASE_14_DEFINITION.md's Editing
                // the Audience section.
                if ($hasDepartments || $hasTeams) {
                    $validator->errors()->add('audience_type', 'audience_type is required when updating department_ids or team_ids.');
                }

                return;
            }

            $audienceType = $this->input('audience_type', AnnouncementAudienceType::CompanyWide->value);

            if ($audienceType === AnnouncementAudienceType::Scoped->value && ! $hasDepartments && ! $hasTeams) {
                $validator->errors()->add('department_ids', 'A scoped announcement requires at least one department or team.');
            }

            if ($audienceType === AnnouncementAudienceType::CompanyWide->value && ($hasDepartments || $hasTeams)) {
                $validator->errors()->add('audience_type', 'A company-wide announcement cannot include department or team targeting.');
            }
        });
    }
}
