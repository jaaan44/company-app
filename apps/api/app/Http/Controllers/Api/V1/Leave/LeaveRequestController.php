<?php

namespace App\Http\Controllers\Api\V1\Leave;

use App\Enums\LeaveRequestActionType;
use App\Enums\LeaveRequestStatus;
use App\Http\Controllers\Api\V1\Leave\Concerns\AuthorizesLeaveRequestVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\ApproveLeaveRequestRequest;
use App\Http\Requests\Leave\CancelLeaveRequestRequest;
use App\Http\Requests\Leave\RejectLeaveRequestRequest;
use App\Http\Requests\Leave\StoreLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The supervisory/administrative Leave Request surface (Phase 13) —
 * self-service lives entirely in MyLeaveRequestController instead.
 * Reads (`index`/`show`) are scoped in-controller
 * (AuthorizesLeaveRequestVisibility), mirroring Phase 12's
 * `work-logs.view` precedent. `store`/`cancel` require
 * `leave-requests.manage` (Administrator-only, route middleware).
 * `approve`/`reject` carry no route-level permission middleware —
 * authority (Administrator, or the requester's direct Manager) is
 * resolved entirely in this controller, mirroring Phase 11/12's
 * row-level write-authority shape.
 */
class LeaveRequestController extends Controller
{
    use AuthorizesLeaveRequestVisibility;

    private const WITH_RELATIONS = ['staff', 'leaveType', 'actions.actor.staff', 'creator.staff'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'staff' => ['sometimes', 'string'],
            'status' => ['sometimes', 'string'],
            'type' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = $this->scopeVisibleLeaveRequests($request, LeaveRequest::query()->with(self::WITH_RELATIONS));

        $leaveRequests = $query
            ->when(
                $request->filled('staff'),
                fn ($query) => $query->where('staff_id', Staff::query()->where('public_id', $request->string('staff')->toString())->value('id') ?? -1)
            )
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

    public function show(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->authorizeShow($request, $leaveRequest);

        return new LeaveRequestResource($leaveRequest->load(self::WITH_RELATIONS));
    }

    /**
     * Administrator-only creation naming another Staff member as
     * requester (docs/phases/V1_PHASE_13_DEFINITION.md — Administrator
     * creation). `leave-requests.manage` is enforced by route
     * middleware. Always creates as 'pending' — an Administrator wanting
     * an already-approved historical record calls approve() separately,
     * keeping every approved request's history consistent.
     */
    public function store(StoreLeaveRequestRequest $request): JsonResponse
    {
        $staff = Staff::query()->where('public_id', $request->validated('staff_id'))->firstOrFail();
        $leaveType = LeaveType::query()->where('public_id', $request->validated('leave_type_id'))->firstOrFail();

        $startDate = $request->date('start_date')?->toDateString() ?? '';
        $endDate = $request->date('end_date')?->toDateString() ?? '';

        $leaveRequest = DB::transaction(function () use ($request, $staff, $leaveType, $startDate, $endDate) {
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
                abort(409, 'This staff member already has a pending or approved leave request that overlaps these dates.');
            }

            $leaveRequest = LeaveRequest::create([
                'staff_id' => $staff->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_days' => $request->totalDaysResolved(),
                'reason' => $request->validated('reason'),
                'status' => LeaveRequestStatus::Pending,
                'created_by_user_id' => $request->user()->id,
            ]);

            $leaveRequest->actions()->create([
                'action' => LeaveRequestActionType::Submitted,
                'acted_by_user_id' => $request->user()->id,
                'note' => 'Recorded by an Administrator on behalf of the staff member.',
            ]);

            return $leaveRequest;
        });

        return (new LeaveRequestResource($leaveRequest->load(self::WITH_RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Approval never re-checks balance sufficiency — the submission-time
     * check already accounts for every outstanding pending/approved
     * request, so the allocated_days invariant holds without a second
     * check here (docs/phases/V1_PHASE_13_DEFINITION.md's Negative
     * Balances).
     */
    public function approve(ApproveLeaveRequestRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->authorizeDecisionAuthority($request, $leaveRequest);

        DB::transaction(function () use ($request, $leaveRequest) {
            $leaveRequest->refresh();

            if ($leaveRequest->status !== LeaveRequestStatus::Pending) {
                abort(409, 'Only a pending leave request may be approved.');
            }

            $leaveRequest->update(['status' => LeaveRequestStatus::Approved]);

            $leaveRequest->actions()->create([
                'action' => LeaveRequestActionType::Approved,
                'acted_by_user_id' => $request->user()->id,
                'note' => $request->validated('note'),
            ]);
        });

        return new LeaveRequestResource($leaveRequest->load(self::WITH_RELATIONS));
    }

    public function reject(RejectLeaveRequestRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->authorizeDecisionAuthority($request, $leaveRequest);

        DB::transaction(function () use ($request, $leaveRequest) {
            $leaveRequest->refresh();

            if ($leaveRequest->status !== LeaveRequestStatus::Pending) {
                abort(409, 'Only a pending leave request may be rejected.');
            }

            $leaveRequest->update(['status' => LeaveRequestStatus::Rejected]);

            $leaveRequest->actions()->create([
                'action' => LeaveRequestActionType::Rejected,
                'acted_by_user_id' => $request->user()->id,
                'note' => $request->validated('reason'),
            ]);
        });

        return new LeaveRequestResource($leaveRequest->load(self::WITH_RELATIONS));
    }

    /**
     * Administrator-only cancellation — may cancel any pending or
     * approved request at any time (correction authority), unlike the
     * requester's own self-service cancel, which is time-windowed for an
     * approved request (docs/phases/V1_PHASE_13_DEFINITION.md's
     * Cancellation). `leave-requests.manage` is enforced by route
     * middleware.
     */
    public function cancel(CancelLeaveRequestRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        DB::transaction(function () use ($request, $leaveRequest) {
            $leaveRequest->refresh();

            if ($leaveRequest->status->isFinal()) {
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

    /**
     * Administrator, or the requester's current direct Manager (holding
     * `leave-requests.view`) — never the requester themselves, even if
     * they somehow satisfied the manager relationship (defense in
     * depth; Staff::wouldCreateCycleWith() already makes self-management
     * structurally impossible).
     */
    private function authorizeDecisionAuthority(Request $request, LeaveRequest $leaveRequest): void
    {
        if ($this->isAdministrator($request)) {
            return;
        }

        $actorStaff = $request->user()?->staff;

        if ($actorStaff !== null && $leaveRequest->staff_id === $actorStaff->id) {
            abort(403, 'You cannot approve or reject your own leave request.');
        }

        if ($this->isDirectManagerOf($request, $leaveRequest->staff)) {
            return;
        }

        abort(403, 'You do not have authority to decide this leave request.');
    }
}
