<?php

namespace App\Models;

use App\Enums\ProjectMilestoneStatus;
use Database\Factories\ProjectMilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A Project's target-date marker (Phase 17 — Scheduler) — the minimal
 * Milestone concept the Phase 17 roadmap dependency incorrectly assumed
 * Phase 10 had already built (Phase 10 deferred it entirely; see
 * docs/DECISIONS.md and docs/phases/V1_PHASE_10_DEFINITION.md's own
 * explicit exclusion). Deliberately small: no percent-complete, no
 * dependency graph between milestones, no nested milestones, no
 * recurrence, no workflow engine. Externally addressable via `public_id`
 * (DEC-017); the internal numeric id is never exposed.
 *
 * @property int $id
 * @property string $public_id
 * @property int $project_id
 * @property string $title
 * @property Carbon $due_date
 * @property ProjectMilestoneStatus $status
 */
#[Fillable(['project_id', 'title', 'due_date', 'status'])]
class ProjectMilestone extends Model
{
    /** @use HasFactory<ProjectMilestoneFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'status' => ProjectMilestoneStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProjectMilestone $milestone): void {
            $milestone->public_id ??= (string) Str::ulid();
            $milestone->status ??= ProjectMilestoneStatus::Pending;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
