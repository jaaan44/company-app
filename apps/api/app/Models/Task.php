<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A unit of work (Phase 11 — Tasks), built on top of Projects & Project
 * Membership (Phase 10). Optionally belongs to a Project (DEC-006 —
 * "internal company tasks must be possible without creating artificial
 * projects"); an independent (project-less) Task and a Project-linked
 * Task share the same model and lifecycle. Externally addressable via
 * `public_id` (DEC-017); the internal numeric id is never exposed. See
 * docs/phases/V1_PHASE_11_DEFINITION.md.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $project_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property int|null $assignee_staff_id
 * @property int|null $created_by_user_id
 * @property Carbon|null $due_date
 * @property Carbon|null $completed_at
 */
#[Fillable([
    'project_id', 'title', 'description', 'status', 'priority',
    'assignee_staff_id', 'created_by_user_id', 'due_date', 'completed_at',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Task $task): void {
            $task->public_id ??= (string) Str::ulid();

            // The migration's DB-level defaults aren't reflected on this
            // in-memory instance after an insert unless refreshed — same
            // pattern as Project/Staff/Department.
            $task->status ??= TaskStatus::Todo;
            $task->priority ??= TaskPriority::Normal;
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

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assignee_staff_id');
    }

    /**
     * The User who created this Task — accountability only, never
     * confused with the assignee. Nullable (a Task may predate this
     * column ever being populated, or the creating context may not have
     * had an authenticated User).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Work Logs referencing this Task (Phase 12). restrictOnDelete on
     * work_logs.task_id backs the deletion guard in
     * TaskController::destroy — a Task with any Work Log cannot be
     * hard-deleted.
     *
     * @return HasMany<WorkLog, $this>
     */
    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }

    /**
     * Service Reports referencing this Task (Phase 18) — optional;
     * restrictOnDelete on service_reports.task_id backs the deletion
     * guard in TaskController::destroy.
     *
     * @return HasMany<ServiceReport, $this>
     */
    public function serviceReports(): HasMany
    {
        return $this->hasMany(ServiceReport::class);
    }
}
