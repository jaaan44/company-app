<?php

namespace App\Http\Controllers\Api\V1\Announcements\Concerns;

use App\Enums\AnnouncementAudienceType;
use App\Enums\NotificationSourceType;
use App\Enums\NotificationType;
use App\Models\Announcement;
use App\Models\Notification;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 15's one real Notification producer, wired into
 * AnnouncementController::publish() only (never on archive, never on any
 * other transition) — the roadmap names Phase 14 as Phase 15's "first
 * real notification producer" (docs/ROADMAP.md). This is a publish-time
 * snapshot: it evaluates the audience's current Department/Team
 * membership once, at the moment of publication, and persists one
 * Notification row per resolved recipient — a later Department/Team
 * change never rewrites, adds to, or removes those rows, deliberately
 * unlike ScopesAnnouncementVisibility's dynamic, current-membership
 * `/me/announcements` feed (see docs/phases/V1_PHASE_15_DEFINITION.md's
 * Snapshot vs Dynamic Meaning section). A Staff member with no linked
 * User account is silently skipped — a Notification cannot be consumed
 * by an account that doesn't exist. Because AnnouncementController::
 * publish() only ever succeeds once per Announcement (guarded by its own
 * `status !== Draft` check), this fan-out structurally cannot run twice
 * for the same Announcement — no separate idempotency key is needed.
 *
 * Content is deliberately generic: the Notification's title is the
 * Announcement's own (non-sensitive, already broadcast) title, and its
 * message is a fixed, generic phrase — never the Announcement body, which
 * the client fetches through its own, separately-authorized
 * /me/announcements/{public_id} endpoint via the notification's `source`.
 */
trait NotifiesAnnouncementAudience
{
    private const NOTIFICATION_MESSAGE = 'A new company announcement has been published.';

    private function notifyAudience(Announcement $announcement): void
    {
        foreach ($this->resolveRecipientUserIds($announcement) as $recipientUserId) {
            Notification::create([
                'recipient_user_id' => $recipientUserId,
                'type' => NotificationType::AnnouncementPublished,
                'title' => $announcement->title,
                'message' => self::NOTIFICATION_MESSAGE,
                'source_type' => NotificationSourceType::Announcement,
                'source_public_id' => $announcement->public_id,
            ]);
        }
    }

    /**
     * @return array<int, int>
     */
    private function resolveRecipientUserIds(Announcement $announcement): array
    {
        $query = Staff::query()->whereNotNull('user_id');

        if ($announcement->audience_type === AnnouncementAudienceType::Scoped) {
            $departmentIds = $announcement->departments()->pluck('departments.id')->all();
            $teamIds = $announcement->teams()->pluck('teams.id')->all();

            $query->where(function (Builder $query) use ($departmentIds, $teamIds) {
                // Always-false base — an empty OR-chain below would
                // otherwise match everything, not nothing. Mirrors
                // ScopesAnnouncementVisibility's identical guard.
                $query->whereRaw('1 = 0');

                if ($departmentIds !== []) {
                    $query->orWhereIn('department_id', $departmentIds);
                }

                if ($teamIds !== []) {
                    $query->orWhereIn('team_id', $teamIds);
                }
            });
        }

        // Each Staff row is distinct and staff.user_id is unique, so this
        // is already deduplicated — a staff member matching both a
        // targeted Department and a targeted Team still appears once.
        return $query->pluck('user_id')->all();
    }
}
