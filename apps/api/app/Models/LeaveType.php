<?php

namespace App\Models;

use App\Enums\LeaveTypeStatus;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Configurable Leave Type master data (Phase 13 — Leave Management).
 * Externally addressable via `public_id` (DEC-017); the internal numeric
 * id is never exposed. See docs/phases/V1_PHASE_13_DEFINITION.md.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string|null $code
 * @property string|null $description
 * @property bool $is_paid
 * @property LeaveTypeStatus $status
 * @property int $sort_order
 */
#[Fillable(['name', 'code', 'description', 'is_paid', 'status', 'sort_order'])]
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'status' => LeaveTypeStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LeaveType $leaveType): void {
            $leaveType->public_id ??= (string) Str::ulid();

            // The migration's DB-level defaults aren't reflected on this
            // in-memory instance after an insert unless refreshed — same
            // pattern as Department/Staff/Project/Task.
            $leaveType->is_paid ??= true;
            $leaveType->status ??= LeaveTypeStatus::Active;
            $leaveType->sort_order ??= 0;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return HasMany<LeaveRequest, $this>
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * @return HasMany<LeaveBalance, $this>
     */
    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }
}
