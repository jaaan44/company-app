<?php

namespace App\Http\Controllers\Api\V1\StaffOperations;

use App\Http\Controllers\Api\V1\StaffOperations\Concerns\RequiresLinkedStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffOperations\StoreOperationalStatusRequest;
use App\Http\Resources\OperationalStatusResource;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Operational status (Phase 9) — a Staff member's current work status
 * (available/busy/in_meeting/in_field/off_duty), deliberately distinct
 * from Staff.status (employment lifecycle, Phase 7). "Update" is modeled
 * as appending a new history entry: the paginated list itself (latest
 * first) is both the current-value source (its first item) and the
 * history — see DEC-032.
 *
 * Self-service ("me") actions require the authenticated user to have a
 * linked Staff record (RequiresLinkedStaff) and need no permission beyond
 * that, mirroring GET /api/v1/auth/me. Viewing another staff member's
 * status requires `staff-status.view` (Administrator/Manager/Staff —
 * company-wide, low sensitivity); an Administrator correcting another
 * staff member's status on their behalf requires `staff-status.manage`
 * (Administrator-only) — both enforced by route middleware
 * (routes/api/v1.php), not here.
 */
class OperationalStatusController extends Controller
{
    use RequiresLinkedStaff;

    public function myIndex(Request $request): AnonymousResourceCollection
    {
        return $this->historyFor($this->resolveAuthenticatedStaff($request), $request);
    }

    public function myStore(StoreOperationalStatusRequest $request): JsonResponse
    {
        return $this->append($this->resolveAuthenticatedStaff($request), $request);
    }

    public function staffIndex(Request $request, Staff $staff): AnonymousResourceCollection
    {
        return $this->historyFor($staff, $request);
    }

    public function staffStore(StoreOperationalStatusRequest $request, Staff $staff): JsonResponse
    {
        return $this->append($staff, $request);
    }

    private function historyFor(Staff $staff, Request $request): AnonymousResourceCollection
    {
        return OperationalStatusResource::collection(
            $staff->operationalStatuses()
                ->latest()
                ->paginate($request->integer('per_page', 20))
        );
    }

    private function append(Staff $staff, StoreOperationalStatusRequest $request): JsonResponse
    {
        $entry = $staff->operationalStatuses()->create([
            'status' => $request->validated('status'),
            'changed_by_user_id' => $request->user()->id,
        ]);

        return (new OperationalStatusResource($entry))
            ->response()
            ->setStatusCode(201);
    }
}
