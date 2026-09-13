<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A piece of company work or engagement (Phase 10 — Projects & Project
 * Membership) — the foundation later modules (Tasks, Work Logs, project
 * activity, reporting) reference. Optionally associated with a Client;
 * deliberately distinct from Organization Structure (internal hierarchy,
 * Phase 6). Externally addressable via `public_id` (DEC-017); the internal
 * numeric id is never exposed. See docs/phases/V1_PHASE_10_DEFINITION.md.
 *
 * @property int $id
 * @property string $public_id
 * @property string|null $project_code
 * @property string $name
 * @property string|null $description
 * @property int|null $client_id
 * @property ProjectStatus $status
 * @property Carbon|null $start_date
 * @property Carbon|null $target_end_date
 * @property Carbon|null $completed_date
 * @property string|null $notes
 */
#[Fillable([
    'project_code', 'name', 'description', 'client_id', 'status',
    'start_date', 'target_end_date', 'completed_date', 'notes',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'start_date' => 'date',
            'target_end_date' => 'date',
            'completed_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            $project->public_id ??= (string) Str::ulid();

            // The migration's DB-level default isn't reflected on this
            // in-memory instance after an insert unless refreshed — same
            // pattern as Department/Team/Position/Staff/Client.
            $project->status ??= ProjectStatus::Planned;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The current Project roster. No historical-period tracking — see
     * ProjectMembership.
     *
     * @return HasMany<ProjectMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }

    /**
     * Tasks belonging to this Project (Phase 11). restrictOnDelete on
     * tasks.project_id backs the deletion guard in
     * ProjectController::destroy.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Work Logs referencing this Project directly (Phase 12) — general
     * Project activity with no specific Task. Does NOT include Work Logs
     * that reference one of this Project's Tasks (those are reached via
     * tasks()->workLogs()); restrictOnDelete on work_logs.project_id backs
     * the deletion guard in ProjectController::destroy.
     *
     * @return HasMany<WorkLog, $this>
     */
    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }

    /**
     * This Project's target-date markers (Phase 17). restrictOnDelete on
     * project_milestones.project_id backs the deletion guard in
     * ProjectController::destroy.
     *
     * @return HasMany<ProjectMilestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class);
    }

    /**
     * Manually created Schedule Entries linked to this Project (Phase
     * 17) — a Schedule Entry's Project link is optional (unlike
     * Milestone's required one). restrictOnDelete on
     * schedule_entries.project_id backs the deletion guard in
     * ProjectController::destroy.
     *
     * @return HasMany<ScheduleEntry, $this>
     */
    public function scheduleEntries(): HasMany
    {
        return $this->hasMany(ScheduleEntry::class);
    }

    /**
     * This Project's single conversation (Phase 16), if one has been
     * created — lazily created on first use of the project messaging
     * surface, never eagerly for every Project. Membership is derived
     * exclusively from memberships() (Project Membership), never an
     * independently managed roster. restrictOnDelete on
     * conversations.project_id backs the deletion guard in
     * ProjectController::destroy.
     *
     * @return HasOne<Conversation, $this>
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }
}
