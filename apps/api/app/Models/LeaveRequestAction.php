<?php

namespace App\Models;

use App\Enums\LeaveRequestActionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single historical event in a Leave Request's approval workflow
 * (Phase 13 — Leave Management) — append-only, never updated in place
 * (DEC-010: history over mutation). No public_id — never independently
 * addressed by URL, only read as part of its parent Leave Request's own
 * resource shape.
 *
 * @property int $id
 * @property int $leave_request_id
 * @property LeaveRequestActionType $action
 * @property int|null $acted_by_user_id
 * @property string|null $note
 */
#[Fillable(['leave_request_id', 'action', 'acted_by_user_id', 'note'])]
class LeaveRequestAction extends Model
{
    protected function casts(): array
    {
        return [
            'action' => LeaveRequestActionType::class,
        ];
    }

    /**
     * @return BelongsTo<LeaveRequest, $this>
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /**
     * The User who performed this action — nullable so the history row
     * itself is never deleted merely because the acting account later
     * is. A later Manager change on the requester's Staff record never
     * rewrites this — it is fixed at the moment the action is recorded
     * (DEC-010).
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }
}
