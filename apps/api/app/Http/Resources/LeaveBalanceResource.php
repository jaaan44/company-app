<?php

namespace App\Http\Resources;

use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Staff member's derived Leave Balance standing for one Leave
 * Type/calendar year (Phase 13). `allocated_days`/`remaining_days` are
 * `null` for an unpaid Leave Type (never tracked/balance-checked) —
 * deliberately not `0`, which would incorrectly imply "no leave
 * allowed." `used_days`/`pending_days` are always derived live from
 * LeaveRequest, never a stored/cached column — see LeaveBalance.
 *
 * This is a plain data-transfer array (built by LeaveBalanceController,
 * not backed by an Eloquent LeaveBalance instance — a Staff member with
 * no leave_balances row yet still gets a resource row reporting
 * allocated_days = 0), not @mixin LeaveBalance.
 *
 * @property array{leave_type: LeaveType, year: int, allocated_days: ?int, used_days: int, pending_days: int, notes: ?string} $resource
 */
class LeaveBalanceResource extends JsonResource
{
    /**
     * Deliberately does not declare its own $resource property —
     * JsonResource's own (public, inherited) property already holds it
     * once parent::__construct() runs; redeclaring it here (e.g. via
     * constructor property promotion) would fatal ("cannot make
     * public property private") since the parent already declares it
     * public.
     *
     * @param  array{leave_type: LeaveType, year: int, allocated_days: ?int, used_days: int, pending_days: int, notes: ?string}  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $leaveType = $this->resource['leave_type'];
        $allocated = $this->resource['allocated_days'];
        $used = $this->resource['used_days'];
        $pending = $this->resource['pending_days'];

        return [
            'leave_type' => [
                'public_id' => $leaveType->public_id,
                'name' => $leaveType->name,
                'code' => $leaveType->code,
            ],
            'year' => $this->resource['year'],
            'is_paid' => $leaveType->is_paid,
            'allocated_days' => $allocated,
            'used_days' => $used,
            'pending_days' => $pending,
            'remaining_days' => $allocated === null ? null : $allocated - $used - $pending,
            'notes' => $this->resource['notes'],
        ];
    }
}
