<?php

namespace App\Http\Controllers\Api\V1\Leave;

use App\Enums\LeaveTypeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\StoreLeaveTypeRequest;
use App\Http\Requests\Leave\UpdateLeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Leave Types — configurable master data (Phase 13 — Leave Management).
 * Read endpoints require `leave-types.view` (Administrator/Manager/
 * Staff — company-wide, mirroring organization.view/staff.view); writes
 * require `leave-types.manage` (Administrator-only) — enforced by route
 * middleware (routes/api/v1.php), not here.
 */
class LeaveTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', new Enum(LeaveTypeStatus::class)],
        ]);

        $leaveTypes = LeaveType::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return LeaveTypeResource::collection($leaveTypes);
    }

    public function store(StoreLeaveTypeRequest $request): JsonResponse
    {
        $leaveType = LeaveType::create($request->validated());

        return (new LeaveTypeResource($leaveType))
            ->response()
            ->setStatusCode(201);
    }

    public function show(LeaveType $leaveType): LeaveTypeResource
    {
        return new LeaveTypeResource($leaveType);
    }

    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType): LeaveTypeResource
    {
        $leaveType->update($request->validated());

        return new LeaveTypeResource($leaveType);
    }

    /**
     * A Leave Type with any Leave Request or Leave Balance still
     * referencing it cannot be deleted — preferring deactivation
     * (`status: inactive`) instead, the same relational-integrity
     * philosophy as DepartmentController::destroy.
     */
    public function destroy(LeaveType $leaveType): JsonResponse
    {
        if ($leaveType->leaveRequests()->exists()) {
            return response()->json([
                'message' => 'This leave type still has leave requests referencing it and cannot be deleted.',
            ], 409);
        }

        if ($leaveType->leaveBalances()->exists()) {
            return response()->json([
                'message' => 'This leave type still has leave balances referencing it and cannot be deleted.',
            ], 409);
        }

        $leaveType->delete();

        return response()->json(status: 204);
    }
}
