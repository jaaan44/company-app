<?php

namespace App\Http\Controllers\Api\V1\Announcements;

use App\Http\Controllers\Api\V1\Announcements\Concerns\ScopesAnnouncementVisibility;
use App\Http\Controllers\Api\V1\StaffOperations\Concerns\RequiresLinkedStaff;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\AnnouncementAcknowledgement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Self-service Announcements (Phase 14) — reuses Phase 9/12/13's
 * `/me/...` precedent exactly: needs only a linked Staff record, no
 * permission. Only ever returns `published` (non-archived) announcements
 * within the requester's own audience — never a draft, never one outside
 * their Department/Team (or company-wide). A `public_id` that exists but
 * is outside the requester's audience is reported as 404, not 403 (its
 * existence is itself sensitive), mirroring MyWorkLogController/
 * MyLeaveRequestController.
 */
class MyAnnouncementController extends Controller
{
    use RequiresLinkedStaff;
    use ScopesAnnouncementVisibility;

    private const WITH_RELATIONS = ['departments', 'teams', 'creator.staff', 'publisher.staff'];

    public function myIndex(Request $request): AnonymousResourceCollection
    {
        $staff = $this->resolveAuthenticatedStaff($request);

        $announcements = $this->scopeVisibleToStaff(
            Announcement::query()->with(self::WITH_RELATIONS),
            $staff,
        )
            ->with(['acknowledgements' => fn ($query) => $query->where('staff_id', $staff->id)])
            ->orderByDesc('published_at')
            ->paginate($request->integer('per_page', 50));

        return AnnouncementResource::collection($announcements);
    }

    public function myShow(Request $request, Announcement $announcement): AnnouncementResource
    {
        $staff = $this->resolveAuthenticatedStaff($request);
        $announcement->load(['departments', 'teams', 'creator.staff', 'publisher.staff']);

        if (! $this->isVisibleToStaff($announcement, $staff)) {
            abort(404);
        }

        $announcement->load(['acknowledgements' => fn ($query) => $query->where('staff_id', $staff->id)]);

        return new AnnouncementResource($announcement);
    }

    /**
     * Idempotent — acknowledging an already-acknowledged announcement
     * returns the same 200 with the original timestamp, never a duplicate
     * row or an error (firstOrCreate, backed by the unique constraint).
     */
    public function myAcknowledge(Request $request, Announcement $announcement): AnnouncementResource
    {
        $staff = $this->resolveAuthenticatedStaff($request);
        $announcement->load(['departments', 'teams', 'creator.staff', 'publisher.staff']);

        if (! $this->isVisibleToStaff($announcement, $staff)) {
            abort(404);
        }

        AnnouncementAcknowledgement::query()->firstOrCreate([
            'announcement_id' => $announcement->id,
            'staff_id' => $staff->id,
        ]);

        $announcement->load(['acknowledgements' => fn ($query) => $query->where('staff_id', $staff->id)]);

        return new AnnouncementResource($announcement);
    }
}
