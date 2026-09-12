<?php

namespace App\Models;

use App\Enums\LeaveRequestStatus;
use Database\Factories\LeaveBalanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Staff member's Leave allocation for one Leave Type/calendar year
 * (Phase 13 — Leave Management). Usage (approved/pending days consumed)
 * is deliberately NOT stored here — always derived live from
 * LeaveRequest to avoid a dual-write risk between a cached
 * remaining-balance column and the requests that actually consume it
 * (see LeaveBalanceResource). No public_id — never independently
 * addressed by URL.
 *
 * @property int $id
 * @property int $staff_id
 * @property int $leave_type_id
 * @property int $year
 * @property int $allocated_days
 * @property string|null $notes
 * @property int|null $created_by_user_id
 */
#[Fillable(['staff_id', 'leave_type_id', 'year', 'allocated_days', 'notes', 'created_by_user_id'])]
class LeaveBalance extends Model
{
    /** @use HasFactory<LeaveBalanceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * The User who set/last updated this allocation — accountability
     * only.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The entitlement an Administrator has set for this Staff
     * member/Leave Type/year, or 0 if no allocation has been recorded
     * yet (docs/phases/V1_PHASE_13_DEFINITION.md's Negative Balances).
     */
    public static function allocatedDaysFor(int $staffId, int $leaveTypeId, int $year): int
    {
        return (int) (self::query()
            ->where('staff_id', $staffId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year)
            ->value('allocated_days') ?? 0);
    }

    /**
     * Total days already consumed by this Staff member's approved Leave
     * Requests for this Leave Type/year — derived live, never cached
     * (see this class's own docblock).
     */
    public static function usedDaysFor(int $staffId, int $leaveTypeId, int $year): int
    {
        return (int) LeaveRequest::query()
            ->where('staff_id', $staffId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereYear('start_date', $year)
            ->where('status', LeaveRequestStatus::Approved)
            ->sum('total_days');
    }

    /**
     * Total days tied up in this Staff member's still-pending Leave
     * Requests for this Leave Type/year — counted alongside used days so
     * a submission can never let outstanding pending requests
     * collectively overdraw the allocation (docs/phases/
     * V1_PHASE_13_DEFINITION.md's Negative Balances).
     */
    public static function pendingDaysFor(int $staffId, int $leaveTypeId, int $year): int
    {
        return (int) LeaveRequest::query()
            ->where('staff_id', $staffId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereYear('start_date', $year)
            ->where('status', LeaveRequestStatus::Pending)
            ->sum('total_days');
    }
}
