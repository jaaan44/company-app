<?php

namespace App\Http\Controllers\Api\V1\Leave;

use App\Enums\LeaveTypeStatus;
use App\Http\Controllers\Api\V1\Leave\Concerns\AuthorizesLeaveRequestVisibility;
use App\Http\Controllers\Api\V1\StaffOperations\Concerns\RequiresLinkedStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\StoreLeaveBalanceRequest;
use App\Http\Resources\LeaveBalanceResource;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Leave Balances (Phase 13) — one derived row per active Leave Type for
 * a given Staff member/calendar year. Self-service (`/me/leave-balances`)
 * needs no permission beyond a linked Staff record, mirroring
 * `/me/work-logs`. Viewing another Staff member's balances is scoped
 * exactly like Leave Request visibility (Administrator: any; Manager:
 * direct reports only, via `leave-requests.view` —
 * AuthorizesLeaveRequestVisibility). Setting/updating an allocation
 * requires `leave-requests.manage` (Administrator-only, route
 * middleware).
 */
class LeaveBalanceController extends Controller
{
    use AuthorizesLeaveRequestVisibility;
    use RequiresLinkedStaff;

    public function myIndex(Request $request): AnonymousResourceCollection
    {
        return $this->balancesFor($this->resolveAuthenticatedStaff($request), $request);
    }

    public function staffIndex(Request $request, Staff $staff): AnonymousResourceCollection
    {
        $this->authorizeManagerOf($request, $staff);

        return $this->balancesFor($staff, $request);
    }

    /**
     * Upserts (create-or-update) the allocation for one Leave
     * Type/year — not a conventional PUT-by-identifier update, since a
     * Leave Balance is never independently addressed by its own
     * public_id (docs/phases/V1_PHASE_13_DEFINITION.md's Leave
     * Balances).
     */
    public function staffStore(StoreLeaveBalanceRequest $request, Staff $staff): LeaveBalanceResource
    {
        /** @var LeaveType $leaveType */
        $leaveType = LeaveType::query()->where('public_id', $request->validated('leave_type_id'))->firstOrFail();
        $year = (int) $request->validated('year');

        LeaveBalance::query()->updateOrCreate(
            ['staff_id' => $staff->id, 'leave_type_id' => $leaveType->id, 'year' => $year],
            [
                'allocated_days' => $request->validated('allocated_days'),
                'notes' => $request->validated('notes'),
                'created_by_user_id' => $request->user()->id,
            ],
        );

        return new LeaveBalanceResource($this->rowFor($staff, $leaveType, $year));
    }

    private function balancesFor(Staff $staff, Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ]);

        $year = $request->integer('year', (int) date('Y'));

        $rows = LeaveType::query()
            ->where('status', LeaveTypeStatus::Active)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveType $leaveType) => $this->rowFor($staff, $leaveType, $year));

        return LeaveBalanceResource::collection($rows);
    }

    /**
     * @return array{leave_type: LeaveType, year: int, allocated_days: ?int, used_days: int, pending_days: int, notes: ?string}
     */
    private function rowFor(Staff $staff, LeaveType $leaveType, int $year): array
    {
        return [
            'leave_type' => $leaveType,
            'year' => $year,
            'allocated_days' => $leaveType->is_paid ? LeaveBalance::allocatedDaysFor($staff->id, $leaveType->id, $year) : null,
            'used_days' => LeaveBalance::usedDaysFor($staff->id, $leaveType->id, $year),
            'pending_days' => LeaveBalance::pendingDaysFor($staff->id, $leaveType->id, $year),
            'notes' => LeaveBalance::query()
                ->where('staff_id', $staff->id)
                ->where('leave_type_id', $leaveType->id)
                ->where('year', $year)
                ->value('notes'),
        ];
    }
}
