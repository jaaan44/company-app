<?php

namespace App\Http\Controllers\Api\V1\StaffOperations;

use App\Http\Controllers\Api\V1\StaffOperations\Concerns\RequiresLinkedStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffOperations\StoreCheckInRequest;
use App\Http\Resources\CheckInResource;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffCheckIn;
use App\Support\CompanyTimezone;
use App\Support\Reporting\OverdueTasks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Location check-in (Phase 9, DEC-005/DEC-032) — an explicit, user-
 * triggered event, never continuous/background tracking. The paginated
 * list itself (latest first) is both the current-location source (its
 * first item) and the history.
 *
 * Self-service ("me") actions require the authenticated user to have a
 * linked Staff record (RequiresLinkedStaff) and need no permission beyond
 * that. Viewing another staff member's check-ins requires `location.view`
 * (route middleware) *and* is further scoped here: a non-Administrator
 * holder (i.e. a Manager) may only view their own direct reports
 * (Staff.manager_id, Phase 7) — location is materially more sensitive
 * than operational status and is never company-wide for Manager/Staff.
 * Deleting/correcting a specific historical check-in requires
 * `location.manage` (Administrator-only, route middleware).
 */
class CheckInController extends Controller
{
    use RequiresLinkedStaff;

    public function myIndex(Request $request): AnonymousResourceCollection
    {
        return $this->historyFor($this->resolveAuthenticatedStaff($request), $request)
            ->additional(['meta' => ['company_day' => $this->companyDay()]]);
    }

    public function myStore(StoreCheckInRequest $request): JsonResponse
    {
        $staff = $this->resolveAuthenticatedStaff($request);

        $checkIn = $staff->checkIns()->create($request->validated());

        return (new CheckInResource($checkIn))
            ->response()
            ->setStatusCode(201);
    }

    public function staffIndex(Request $request, Staff $staff): AnonymousResourceCollection
    {
        $this->authorizeViewingLocationOf($request, $staff);

        return $this->historyFor($staff, $request);
    }

    /**
     * Administrator-only deletion/correction of a single historical
     * check-in (`location.manage`, enforced by route middleware). No
     * general audit subsystem — this is a deliberate, narrow exception to
     * "check-ins are otherwise never mutated."
     */
    public function destroy(StaffCheckIn $checkIn): JsonResponse
    {
        $checkIn->delete();

        return response()->json(status: 204);
    }

    private function historyFor(Staff $staff, Request $request): AnonymousResourceCollection
    {
        return CheckInResource::collection(
            $staff->checkIns()
                ->latest()
                // Unique tie-breaker (Phase 30B, R-14): rows created in the
                // same second otherwise have no defined order, so a paged
                // client could see one twice or never.
                ->orderByDesc('id')
                ->paginate($request->integer('per_page', 20))
        );
    }

    /**
     * `location.view` (route middleware) grants the ability to view
     * *someone's* check-ins, but a Manager may only see their own direct
     * reports — company-wide precise-location visibility is never granted
     * to Manager/Staff. Administrator bypasses this scoping entirely via
     * the centralized Gate::before override.
     */
    private function authorizeViewingLocationOf(Request $request, Staff $target): void
    {
        $user = $request->user();

        if ($user->hasRole(Role::ADMINISTRATOR)) {
            return;
        }

        $requesterStaff = $user->staff;

        if ($requesterStaff !== null && $target->manager_id === $requesterStaff->id) {
            return;
        }

        abort(403, 'You may only view the location history of your direct reports.');
    }

    /**
     * The company day (Phase 30B, R-14) — additive: the paginator's own
     * `meta` keys are unchanged. Clients show times in company time with
     * `utc_offset` and judge "today" from `date`, never from the device
     * clock (DEC-052).
     *
     * @return array{date: string, timezone: string, utc_offset: string}
     */
    private function companyDay(): array
    {
        $today = OverdueTasks::todayInCompanyTimezone();

        return [
            'date' => $today,
            'timezone' => CompanyTimezone::value(),
            'utc_offset' => Carbon::parse($today, CompanyTimezone::value())->startOfDay()->format('P'),
        ];
    }
}
