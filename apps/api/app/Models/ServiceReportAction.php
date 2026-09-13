<?php

namespace App\Models;

use App\Enums\ServiceReportActionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single historical event in a Service Report's workflow (Phase 18 —
 * Service Reports) — append-only, never updated in place (DEC-010). No
 * public_id — never independently addressed by URL, only read as part of
 * its parent Service Report's own resource shape. Mirrors
 * LeaveRequestAction's exact shape (Phase 13).
 *
 * @property int $id
 * @property int $service_report_id
 * @property ServiceReportActionType $action
 * @property int|null $acted_by_user_id
 * @property string|null $note
 */
#[Fillable(['service_report_id', 'action', 'acted_by_user_id', 'note'])]
class ServiceReportAction extends Model
{
    protected function casts(): array
    {
        return [
            'action' => ServiceReportActionType::class,
        ];
    }

    /**
     * @return BelongsTo<ServiceReport, $this>
     */
    public function serviceReport(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class);
    }

    /**
     * The User who performed this action — nullable so the history row
     * itself is never deleted merely because the acting account later
     * is. Fixed at the moment the action is recorded (DEC-010) — never
     * rewritten by a later change (e.g. the creator's manager changing).
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }
}
