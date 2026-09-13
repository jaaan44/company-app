<?php

namespace App\Models;

use App\Enums\ScheduleEntryActivityType;
use Database\Factories\ScheduleEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A manually created schedule item (Phase 17 — Scheduler) for an
 * activity with no other system-of-record module — a meeting, client
 * visit, service appointment, company event, training session, or other
 * internal activity (App\Enums\ScheduleEntryActivityType). Task due
 * dates, approved Leave Requests, and Project Milestones are never
 * copied in here — they remain their own systems of record and are
 * joined into the unified Scheduler projection only at read time (see
 * App\Http\Controllers\Api\V1\Scheduling\ScheduleController). Externally
 * addressable via `public_id` (DEC-017); the internal numeric id is
 * never exposed.
 *
 * @property int $id
 * @property string $public_id
 * @property int $creator_staff_id
 * @property string $title
 * @property string|null $description
 * @property ScheduleEntryActivityType $activity_type
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $is_all_day
 * @property int|null $project_id
 */
#[Fillable([
    'creator_staff_id', 'title', 'description', 'activity_type',
    'starts_at', 'ends_at', 'is_all_day', 'project_id',
])]
class ScheduleEntry extends Model
{
    /** @use HasFactory<ScheduleEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'activity_type' => ScheduleEntryActivityType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_all_day' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ScheduleEntry $entry): void {
            $entry->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * The Staff member who created this entry — a real, standing
     * business-authority column (not mere accountability metadata like
     * tasks.created_by_user_id), since the creator retains edit/delete
     * authority over their own entry for its lifetime.
     *
     * @return BelongsTo<Staff, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'creator_staff_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Staff members this entry appears on the schedule of, beyond its
     * creator (who is never duplicated into this pivot — see
     * docs/phases/V1_PHASE_17_DEFINITION.md's Participants design).
     * Participation carries no RSVP/invited/accepted/declined/tentative
     * state — it means only "this appears on their schedule and they may
     * view it."
     *
     * @return BelongsToMany<Staff, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'schedule_entry_participants');
    }
}
