<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Self-service, in-app Notifications (Phase 15) — entirely `/me/...`,
 * scoped to the authenticated User's own recipient identity. Unlike every
 * other `/me/...` surface in this API (status, check-ins, work-logs,
 * leave-requests, announcements), this one needs no linked Staff record
 * at all: recipient identity is the User account itself (DEC-038), not
 * Staff. There is deliberately no create/update/delete endpoint —
 * Notifications are produced only by internal application code (see
 * App\Http\Controllers\Api\V1\Announcements\Concerns\
 * NotifiesAnnouncementAudience) — and no Admin management surface exists
 * at all (docs/phases/V1_PHASE_15_DEFINITION.md's Administrative
 * Notification API section).
 *
 * A notification belonging to a different User is reported as 404, not
 * 403 (its existence is itself sensitive), mirroring every other
 * ownership-scoped `/me/...` resource in this API.
 */
class NotificationController extends Controller
{
    public function myIndex(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'unread' => ['sometimes', 'boolean'],
            'type' => ['sometimes', new Enum(NotificationType::class)],
        ]);

        $notifications = Notification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        return NotificationResource::collection($notifications);
    }

    public function myUnreadCount(Request $request): JsonResponse
    {
        $count = Notification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['data' => ['unread_count' => $count]]);
    }

    public function myShow(Request $request, Notification $notification): NotificationResource
    {
        $this->authorizeOwnership($request, $notification);

        return new NotificationResource($notification);
    }

    /**
     * Idempotent — marking an already-read notification read again leaves
     * its original read_at untouched and still returns 200 (preferred
     * lean behavior: preserve the original first-read timestamp — see
     * docs/phases/V1_PHASE_15_DEFINITION.md's Mark Read section).
     */
    public function myMarkRead(Request $request, Notification $notification): NotificationResource
    {
        $this->authorizeOwnership($request, $notification);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return new NotificationResource($notification);
    }

    /**
     * Affects only notifications belonging to the authenticated recipient
     * and currently unread — returns a bounded updated_count, never the
     * full list of affected records.
     */
    public function myMarkAllRead(Request $request): JsonResponse
    {
        $updatedCount = Notification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['updated_count' => $updatedCount]]);
    }

    private function authorizeOwnership(Request $request, Notification $notification): void
    {
        if ($notification->recipient_user_id !== $request->user()->id) {
            abort(404);
        }
    }
}
