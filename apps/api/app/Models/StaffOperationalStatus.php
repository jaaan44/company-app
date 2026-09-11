<?php

namespace App\Models;

use App\Enums\OperationalStatus;
use Database\Factories\StaffOperationalStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single operational-status history entry for a Staff member (Phase 9,
 * DEC-032) — append-only, never updated in place (DEC-010: history over
 * mutation). "Current status" is derived as the latest row for a given
 * Staff (see Staff::latestOperationalStatus()), not a separate mutable
 * column. Deliberately distinct from `Staff.status` (App\Enums\StaffStatus,
 * employment lifecycle, Phase 7) — this table is never read or written
 * alongside that column.
 *
 * @property int $id
 * @property int $staff_id
 * @property OperationalStatus $status
 * @property int|null $changed_by_user_id
 */
#[Fillable(['staff_id', 'status', 'changed_by_user_id'])]
class StaffOperationalStatus extends Model
{
    /** @use HasFactory<StaffOperationalStatusFactory> */
    use HasFactory;

    protected $table = 'staff_statuses';

    protected function casts(): array
    {
        return [
            'status' => OperationalStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * The user who performed this change — the staff member themselves, or
     * an Administrator correcting it on their behalf. Nullable; lightweight
     * traceability only, not a general audit subsystem.
     *
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
