<?php

namespace App\Models;

use App\Enums\ServiceReportStatus;
use Database\Factories\ServiceReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A record of service/work performed for a Client (Phase 18 — Service
 * Reports), built on top of Clients (Phase 8), Projects (Phase 10), Tasks
 * (Phase 11), and Staff (Phase 7). Client is the required business
 * anchor — a Service Report is never a generic internal activity record;
 * Project and Task are both optional (mirroring DEC-006's "optional
 * project" precedent). Externally addressable via `public_id` (DEC-017);
 * the internal numeric id is never exposed. See docs/phases/
 * V1_PHASE_18_DEFINITION.md and DEC-041.
 *
 * @property int $id
 * @property string $public_id
 * @property int $client_id
 * @property int|null $project_id
 * @property int|null $task_id
 * @property int $creator_staff_id
 * @property int|null $created_by_user_id
 * @property Carbon $service_date
 * @property string $work_performed
 * @property string|null $findings
 * @property string|null $recommendations
 * @property string|null $follow_up_actions
 * @property string|null $site_representative_name
 * @property ServiceReportStatus $status
 */
#[Fillable([
    'client_id', 'project_id', 'task_id', 'creator_staff_id', 'created_by_user_id',
    'service_date', 'work_performed', 'findings', 'recommendations',
    'follow_up_actions', 'site_representative_name', 'status',
])]
class ServiceReport extends Model
{
    /** @use HasFactory<ServiceReportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'status' => ServiceReportStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ServiceReport $report): void {
            $report->public_id ??= (string) Str::ulid();
            $report->status ??= ServiceReportStatus::Draft;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
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
     * The primary performer — never nullable. A real, standing
     * business-authority relation (mirrors ScheduleEntry::creator()):
     * this Staff member retains draft edit/delete/submit authority over
     * their own report.
     *
     * @return BelongsTo<Staff, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'creator_staff_id');
    }

    /**
     * The User who created this record — accountability only, never
     * confused with creator() (the performer). Nullable — mirrors
     * Task::creator()/WorkLog::creator().
     *
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Additional Staff participants beyond the creator (docs/phases/
     * V1_PHASE_18_DEFINITION.md's Participants) — no role/status
     * hierarchy, no RSVP, no workflow-management authority. The creator
     * is deliberately never duplicated into this pivot, mirroring
     * ScheduleEntry::participants()'s identical precedent.
     *
     * @return BelongsToMany<Staff, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'service_report_participants');
    }

    /**
     * Full append-only workflow-transition history (DEC-010) — oldest to
     * newest, mirroring LeaveRequest::actions()'s identical shape.
     *
     * @return HasMany<ServiceReportAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(ServiceReportAction::class)->orderBy('created_at');
    }

    /**
     * This report's file attachments (Phase 18, DEC-041) — mutable only
     * while the report is still a draft (see
     * ServiceReportAttachmentController).
     *
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
