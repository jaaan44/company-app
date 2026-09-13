<?php

namespace App\Models;

use App\Enums\IncidentReportActionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single historical event in an Incident Report's workflow (Phase 19)
 * — append-only, never updated in place (DEC-010). No public_id — never
 * independently addressed by URL, only read as part of its parent
 * Incident Report's own resource shape. Mirrors ServiceReportAction's
 * exact shape (Phase 18).
 *
 * @property int $id
 * @property int $incident_report_id
 * @property IncidentReportActionType $action
 * @property int|null $acted_by_user_id
 * @property string|null $note
 */
#[Fillable(['incident_report_id', 'action', 'acted_by_user_id', 'note'])]
class IncidentReportAction extends Model
{
    protected function casts(): array
    {
        return [
            'action' => IncidentReportActionType::class,
        ];
    }

    /**
     * @return BelongsTo<IncidentReport, $this>
     */
    public function incidentReport(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class);
    }

    /**
     * The User who performed this action — nullable so the history row
     * itself is never deleted merely because the acting account later
     * is. Fixed at the moment the action is recorded (DEC-010).
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }
}
