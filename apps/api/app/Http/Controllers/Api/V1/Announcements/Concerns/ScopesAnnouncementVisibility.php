<?php

namespace App\Http\Controllers\Api\V1\Announcements\Concerns;

use App\Enums\AnnouncementAudienceType;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared by MyAnnouncementController. An Announcement is visible to a
 * Staff member only when it is `published` (never draft/archived) and
 * either company-wide, or scoped to a Department/Team the Staff member is
 * currently a member of (union — see docs/phases/
 * V1_PHASE_14_DEFINITION.md's Audience / Targeting section). Current
 * membership is evaluated at read time, never a snapshot.
 */
trait ScopesAnnouncementVisibility
{
    /**
     * @param  Builder<Announcement>  $query
     * @return Builder<Announcement>
     */
    private function scopeVisibleToStaff(Builder $query, Staff $staff): Builder
    {
        return $query
            ->where('status', AnnouncementStatus::Published->value)
            ->where(function (Builder $query) use ($staff) {
                $query->where('audience_type', AnnouncementAudienceType::CompanyWide->value);

                $query->orWhere(function (Builder $query) use ($staff) {
                    $query->where('audience_type', AnnouncementAudienceType::Scoped->value);

                    $query->where(function (Builder $query) use ($staff) {
                        // Always-false base — an empty OR-chain below would
                        // otherwise match everything, not nothing.
                        $query->whereRaw('1 = 0')
                            ->when(
                                $staff->department_id !== null,
                                fn (Builder $query) => $query->orWhereHas(
                                    'departments',
                                    fn (Builder $query) => $query->where('departments.id', $staff->department_id)
                                )
                            )
                            ->when(
                                $staff->team_id !== null,
                                fn (Builder $query) => $query->orWhereHas(
                                    'teams',
                                    fn (Builder $query) => $query->where('teams.id', $staff->team_id)
                                )
                            );
                    });
                });
            });
    }

    /**
     * Boolean equivalent of scopeVisibleToStaff() for a single, already-
     * loaded Announcement (its `departments`/`teams` relations must be
     * loaded) — used by myShow()/myAcknowledge() rather than re-querying.
     */
    private function isVisibleToStaff(Announcement $announcement, Staff $staff): bool
    {
        if ($announcement->status !== AnnouncementStatus::Published) {
            return false;
        }

        if ($announcement->audience_type === AnnouncementAudienceType::CompanyWide) {
            return true;
        }

        return ($staff->department_id !== null && $announcement->departments->contains('id', $staff->department_id))
            || ($staff->team_id !== null && $announcement->teams->contains('id', $staff->team_id));
    }
}
