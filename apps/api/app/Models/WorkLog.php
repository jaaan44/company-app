<?php

namespace App\Models;

use Database\Factories\WorkLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A historical record of work performed by a Staff member (Phase 12),
 * built on top of Staff/Projects/Project Membership/Tasks. Optionally
 * references a Task and/or a Project (never neither) — see
 * docs/phases/V1_PHASE_12_DEFINITION.md for the full parent-model
 * rationale and the "single source of truth" rule preventing
 * project_id/task_id inconsistency. Deliberately not a payroll,
 * attendance, or timesheet record — see that document's Critical Domain
 * Boundary. Externally addressable via `public_id` (DEC-017); the
 * internal numeric id is never exposed.
 *
 * @property int $id
 * @property string $public_id
 * @property int $staff_id
 * @property int|null $task_id
 * @property int|null $project_id
 * @property int|null $created_by_user_id
 * @property Carbon $work_date
 * @property int $duration_minutes
 * @property string $description
 */
#[Fillable([
    'staff_id', 'task_id', 'project_id', 'created_by_user_id',
    'work_date', 'duration_minutes', 'description',
])]
class WorkLog extends Model
{
    /** @use HasFactory<WorkLogFactory> */
    use HasFactory;

    protected $table = 'work_logs';

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WorkLog $workLog): void {
            $workLog->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * The Staff member who performed the work — never nullable. Deliberately
     * distinct from creator() (accountability for entering the record).
     *
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The User who created this Work Log record — accountability only,
     * never confused with the performer (staff()). Nullable — mirrors
     * Task::creator() (Phase 11).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
