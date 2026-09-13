<?php

namespace App\Models;

use App\Enums\IncidentReportActionType;
use App\Enums\IncidentReportStatus;
use App\Enums\IncidentReportType;
use App\Enums\IncidentSeverity;
use Database\Factories\IncidentReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A record of an operational incident (Phase 19 — Incident Reports,
 * DEC-042), built on top of Clients (Phase 8), Projects (Phase 10), Tasks
 * (Phase 11), and Staff (Phase 7), and the second authorized consumer of
 * the shared `attachments` infrastructure (Phase 18, DEC-041). Unlike
 * Service Reports, Client/Project/Task are all optional — an Incident
 * Report may be entirely internal. `reporter_staff_id` is the standing,
 * immutable-after-creation business anchor; `assigned_to_staff_id` is the
 * single (nullable) investigator. Externally addressable via `public_id`
 * (DEC-017); the internal numeric id is never exposed. See
 * docs/phases/V1_PHASE_19_DEFINITION.md and DEC-042.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $client_id
 * @property int|null $project_id
 * @property int|null $task_id
 * @property int $reporter_staff_id
 * @property int|null $assigned_to_staff_id
 * @property int|null $created_by_user_id
 * @property Carbon $occurred_at
 * @property string|null $location
 * @property IncidentReportType $incident_type
 * @property IncidentSeverity $severity
 * @property string $description
 * @property string|null $immediate_action_taken
 * @property string|null $root_cause
 * @property string|null $corrective_action
 * @property string|null $preventive_action
 * @property string|null $follow_up_actions
 * @property string|null $resolution
 * @property string|null $people_involved
 * @property string|null $witness_notes
 * @property IncidentReportStatus $status
 */
#[Fillable([
    'client_id', 'project_id', 'task_id', 'reporter_staff_id', 'assigned_to_staff_id',
    'created_by_user_id', 'occurred_at', 'location', 'incident_type', 'severity',
    'description', 'immediate_action_taken', 'root_cause', 'corrective_action',
    'preventive_action', 'follow_up_actions', 'resolution', 'people_involved',
    'witness_notes', 'status',
])]
class IncidentReport extends Model
{
    /** @use HasFactory<IncidentReportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'incident_type' => IncidentReportType::class,
            'severity' => IncidentSeverity::class,
            'status' => IncidentReportStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (IncidentReport $report): void {
            $report->public_id ??= (string) Str::ulid();
            $report->status ??= IncidentReportStatus::Reported;
            $report->severity ??= IncidentSeverity::Medium;
        });

        // The initial `reported` action-history entry is recorded here —
        // once, for every creation path (API, factory, seeder) — rather
        // than in IncidentReportController::store(), so the action
        // history is always a complete, accurate record of this report's
        // life regardless of how it came to exist (docs/phases/
        // V1_PHASE_19_DEFINITION.md's Action History).
        static::created(function (IncidentReport $report): void {
            $report->actions()->create([
                'action' => IncidentReportActionType::Reported,
                'acted_by_user_id' => $report->created_by_user_id,
            ]);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Normalizes any client-supplied offset to a true UTC instant before
     * it ever reaches storage — Eloquent's own `datetime` cast (see
     * casts() above) only reformats a value for the database, it does
     * not convert timezones, so a bare `Carbon::parse()` would otherwise
     * silently store the wall-clock time of whatever offset was
     * submitted, mislabeled as UTC (docs/phases/V1_PHASE_19_DEFINITION.md
     * requires genuine UTC storage, not merely trusting the caller
     * already converted). Read access is untouched — the `datetime` cast
     * still governs how the stored value is rehydrated into a Carbon
     * instance.
     */
    protected function occurredAt(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value) => $value === null ? null : Carbon::parse($value)->utc(),
        );
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * The Staff member whose incident report this is — never nullable,
     * immutable after creation at every status. A real, standing
     * business-authority relation (mirrors ServiceReport::creator()).
     *
     * @return BelongsTo<Staff, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'reporter_staff_id');
    }

    /**
     * The single Staff member currently responsible for investigating/
     * resolving this incident — nullable (an incident may be unassigned).
     * Mutated only via the dedicated assign/reassign/start-investigation
     * action endpoints, never a generic update.
     *
     * @return BelongsTo<Staff, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_to_staff_id');
    }

    /**
     * The User who created this record — accountability only, never
     * confused with reporter() (the Staff member whose report this is).
     * Nullable — mirrors ServiceReport::createdByUser().
     *
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Additional Staff participants beyond the reporter/assignee — no
     * role/status hierarchy, no RSVP, no witness/injury-role enum
     * (docs/phases/V1_PHASE_19_DEFINITION.md's Participants). The
     * reporter and assignee are deliberately never duplicated into this
     * pivot — their standing authority is derived directly from
     * reporter_staff_id/assigned_to_staff_id.
     *
     * @return BelongsToMany<Staff, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'incident_report_participants');
    }

    /**
     * Full append-only workflow/assignment history (DEC-010) — oldest to
     * newest, mirroring ServiceReport::actions()'s identical shape.
     *
     * @return HasMany<IncidentReportAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(IncidentReportAction::class)->orderBy('created_at');
    }

    /**
     * This report's file attachments (Phase 19, DEC-042) — the second
     * authorized consumer of the shared Phase 18 attachment
     * infrastructure. Mutable only while `isContentMutable()`, and only
     * by an actor currently authorized to manage this report (see
     * AuthorizesIncidentReportAccess/IncidentReportAttachmentController).
     *
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
