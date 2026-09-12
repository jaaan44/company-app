<?php

namespace App\Http\Requests\Leave\Concerns;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared date-range/overlap/Leave-Type-validity checks for Leave Request
 * creation (Phase 13) — enforced identically for self-service and
 * Administrator-on-behalf creation. These are structural consistency
 * rules, not staff eligibility (see docs/phases/
 * V1_PHASE_13_DEFINITION.md's "Leave Type Validity vs. Self-Service
 * Staff Eligibility") — both creation paths run this same check.
 *
 * Requires ResolvesLeaveRequestReferences (resolveLeaveType()) on the
 * same class.
 */
trait ValidatesLeaveDateRangeAndOverlap
{
    private ?int $resolvedTotalDays = null;

    private function validateDateRangeAndOverlap(Validator $validator, ?int $staffId): void
    {
        if ($validator->errors()->has('start_date') || $validator->errors()->has('end_date')) {
            return;
        }

        $startDate = $this->date('start_date');
        $endDate = $this->date('end_date');

        if ($startDate === null || $endDate === null) {
            return;
        }

        if ($startDate->year !== $endDate->year) {
            $validator->errors()->add('end_date', 'A leave request may not span two different calendar years.');

            return;
        }

        // Inclusive calendar-day count — no business-day/holiday-aware
        // calculation, weekends not excluded (docs/phases/
        // V1_PHASE_13_DEFINITION.md's Leave Quantity).
        $this->resolvedTotalDays = (int) $startDate->diffInDays($endDate) + 1;

        $leaveType = $this->resolveLeaveType();

        if ($leaveType instanceof LeaveType && $leaveType->status !== LeaveTypeStatus::Active) {
            $validator->errors()->add('leave_type_id', 'This leave type is not currently active.');
        }

        if ($staffId === null) {
            return;
        }

        // whereDate() (not a plain where()) — a `date`-cast column can be
        // persisted with a full datetime suffix depending on driver
        // (e.g. SQLite), which would break a plain lexicographic string
        // comparison exactly on a same-day boundary.
        $overlaps = LeaveRequest::query()
            ->where('staff_id', $staffId)
            ->whereIn('status', [LeaveRequestStatus::Pending, LeaveRequestStatus::Approved])
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereDate('end_date', '>=', $startDate->toDateString())
            ->exists();

        if ($overlaps) {
            $validator->errors()->add('start_date', 'You already have a pending or approved leave request that overlaps these dates.');
        }
    }

    private function totalDays(): int
    {
        return $this->resolvedTotalDays ?? 0;
    }
}
