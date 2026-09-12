<?php

namespace App\Http\Controllers\Api\V1\Leave;

use App\Enums\LeaveRequestActionType;
use App\Enums\LeaveRequestStatus;
use App\Http\Controllers\Api\V1\Leave\Concerns\ChecksLeaveBalanceAvailability;
use App\Http\Controllers\Api\V1\StaffOperations\Concerns\RequiresLinkedStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\CancelLeaveRequestRequest;
use App\Http\Requests\Leave\StoreMyLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Self-service Leave Requests (Phase 13) — reuses Phase 9/12's `/me/...`
 * precedent exactly: the requester's Staff identity is always
 * server-derived from the authenticated User's own linked Staff record,
 * never a client-supplied public ID. Requires a linked Staff record and
 * (for creation only) an active employment status — no permission is
 * needed. A public_id that exists but belongs to a different Staff
 * member is reported as 404, not 403 (its existence is itself
 * sensitive), mirroring MyWorkLogController.
 */
class MyLeaveRequestController extends Controller
{
    use ChecksLeaveBalanceAvailability;
    use RequiresLinkedStaff;

    private const WITH_RELATIONS = ['staff', 'leaveType', 'actions.actor.staff', 'creator.staff'];

    public function myIndex(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', 'string'],
            'type' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $staff = $this->resolveAuthenticatedStaff($request);

        $leaveRequests = $staff->leaveRequests()
            ->with(self::WITH_RELATIONS)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when(
                $request->filled('type'),
                fn ($query) => $query->where('leave_type_id', LeaveType::query()->where('public_id', $request->string('type')->toString())->value('id') ?? -1)
            )
            ->when($request->filled('from'), fn ($query) => $query->whereDate('end_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('start_date', '<=', $request->date('to')))
            ->orderByDesc('start_date')
            ->paginate($request->integer('per_page', 50));

        return LeaveRequestResource::collection($leaveRequests);
    }

    public function myStore(StoreMyLeaveRequestRequest $request): JsonResponse
    {
        $staff = $this->resolveAuthenticatedStaff($request);

        /** @var LeaveType $leaveType */
        $leaveType = LeaveType::query()->where('public_id', $request->validated('leave_type_id'))->firstOrFail();

        $startDate = $request->date('start_date')?->toDateString() ?? '';
        $endDate = $request->date('end_date')?->toDateString() ?? '';
        $totalDays = $request->totalDaysResolved();
        $year = (int) $request->date('start_date')?->year;

        $leaveRequest = DB::transaction(function () use ($request, $staff, $leaveType, $startDate, $endDate, $totalDays, $year) {
            $this->assertSufficientBalance($leaveType, $staff->id, $year, $totalDays);

            // whereDate() — see the identical note in
            // ValidatesLeaveDateRangeAndOverlap on why a plain where()
            // would be unsafe against a date-cast column's storage
            // format.
            $overlaps = LeaveRequest::query()
                ->where('staff_id', $staff->id)
                ->whereIn('status', [LeaveRequestStatus::Pending, LeaveRequestStatus::Approved])
                ->whereDate('start_date', '<=', $endDate)
                ->whereDate('end_date', '>=', $startDate)
                ->lockForUpdate()
                ->exists();

            if ($overlaps) {
                abort(409, 'You already have a pending or approved leave request that overlaps these dates.');
            }

            $leaveRequest = LeaveRequest::create([
                'staff_id' => $staff->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_days' => $totalDays,
                'reason' => $request->validated('reason'),
                'status' => LeaveRequestStatus::Pending,
                'created_by_user_id' => $request->user()->id,
            ]);

            $leaveRequest->actions()->create([
                'action' => LeaveRequestActionType::Submitted,
                'acted_by_user_id' => $request->user()->id,
            ]);

            return $leaveRequest;
        });

        return (new LeaveRequestResource($leaveRequest->load(self::WITH_RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function myShow(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $staff = $this->resolveAuthenticatedStaff($request);
        $this->authorizeOwnership($leaveRequest, $staff->id);

        return new LeaveRequestResource($leaveRequest->load(self::WITH_RELATIONS));
    }

    /**
     * Pending: cancellable at any time. Approved: cancellable only while
     * start_date is still in the future — the leave has not started
     * (docs/phases/V1_PHASE_13_DEFINITION.md's Cancellation).
     */
    public function myCancel(CancelLeaveRequestRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $staff = $this->resolveAuthenticatedStaff($request);
        $this->authorizeOwnership($leaveRequest, $staff->id);

        DB::transaction(function () use ($request, $leaveRequest) {
            $leaveRequest->refresh();

            if ($leaveRequest->status === LeaveRequestStatus::Approved && $leaveRequest->start_date->isPast()) {
                abort(409, 'This leave request has already started and can no longer be self-cancelled.');
            }

            if ($leaveRequest->status !== LeaveRequestStatus::Pending && $leaveRequest->status !== LeaveRequestStatus::Approved) {
                abort(409, 'This leave request is no longer active and cannot be cancelled.');
            }

            $leaveRequest->update(['status' => LeaveRequestStatus::Cancelled]);

            $leaveRequest->actions()->create([
                'action' => LeaveRequestActionType::Cancelled,
                'acted_by_user_id' => $request->user()->id,
                'note' => $request->validated('reason'),
            ]);
        });

        return new LeaveRequestResource($leaveRequest->load(self::WITH_RELATIONS));
    }

    private function authorizeOwnership(LeaveRequest $leaveRequest, int $staffId): void
    {
        if ($leaveRequest->staff_id !== $staffId) {
            abort(404);
        }
    }
}
