<?php

namespace App\Models;

use App\Enums\LeaveRequestStatus;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An employee's requested/approved absence (Phase 13 — Leave
 * Management), built on top of Staff (Phase 7). Deliberately not
 * payroll, attendance, or Work Logs — see docs/phases/
 * V1_PHASE_13_DEFINITION.md's Critical Domain Boundary. No
 * submitted_at/approved_at/rejected_at/cancelled_at columns — submission
 * time is created_at, and every decision/cancellation timestamp lives
 * only on leaveRequestActions() to avoid a dual-write risk. Externally
 * addressable via `public_id` (DEC-017); the internal numeric id is
 * never exposed.
 *
 * @property int $id
 * @property string $public_id
 * @property int $staff_id
 * @property int $leave_type_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $total_days
 * @property string $reason
 * @property LeaveRequestStatus $status
 * @property int|null $created_by_user_id
 */
#[Fillable([
    'staff_id', 'leave_type_id', 'start_date', 'end_date', 'total_days',
    'reason', 'status', 'created_by_user_id',
])]
class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => LeaveRequestStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LeaveRequest $leaveRequest): void {
            $leaveRequest->public_id ??= (string) Str::ulid();
            $leaveRequest->status ??= LeaveRequestStatus::Pending;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * The requester — never nullable.
     *
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
     * The User who created this record — accountability only. Nullable;
     * distinguishes a self-service request from one an Administrator
     * entered on the staff member's behalf. Never confused with staff()
     * (the requester).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Full approval-history log (Phase 13) — append-only, oldest to
     * newest (DEC-010).
     *
     * @return HasMany<LeaveRequestAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(LeaveRequestAction::class)->orderBy('created_at');
    }
}
