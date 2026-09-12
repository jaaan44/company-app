<?php

namespace App\Models;

use App\Enums\AnnouncementAudienceType;
use App\Enums\AnnouncementStatus;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Internal broadcast content (Phase 14 — Announcements). Not messaging,
 * chat, comments, or a notification feed — see docs/phases/
 * V1_PHASE_14_DEFINITION.md's Critical Domain Boundary. Externally
 * addressable via `public_id` (DEC-017); the internal numeric id is never
 * exposed.
 *
 * @property int $id
 * @property string $public_id
 * @property string $title
 * @property string $body
 * @property AnnouncementStatus $status
 * @property AnnouncementAudienceType $audience_type
 * @property Carbon|null $published_at
 * @property int|null $created_by_user_id
 * @property int|null $published_by_user_id
 */
#[Fillable(['title', 'body', 'status', 'audience_type', 'published_at', 'created_by_user_id', 'published_by_user_id'])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AnnouncementStatus::class,
            'audience_type' => AnnouncementAudienceType::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Announcement $announcement): void {
            $announcement->public_id ??= (string) Str::ulid();

            // The migration's DB-level defaults aren't reflected on this
            // in-memory instance after an insert unless refreshed — same
            // pattern as Department/Staff/Project/Task/LeaveType.
            $announcement->status ??= AnnouncementStatus::Draft;
            $announcement->audience_type ??= AnnouncementAudienceType::CompanyWide;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * The User who drafted this record — accountability only. Nullable;
     * never confused with publisher() (who actually published it).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The Administrator who actually ran the publish action — may differ
     * from creator() when one Administrator drafts and another publishes.
     *
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * This announcement's targeted Departments — meaningful only when
     * audience_type is Scoped; empty for CompanyWide. Union semantics with
     * teams() — see docs/phases/V1_PHASE_14_DEFINITION.md.
     *
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'announcement_departments');
    }

    /**
     * This announcement's targeted Teams — see departments().
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'announcement_teams');
    }

    /**
     * Staff members who have acknowledged this announcement — not "read"
     * tracking (see docs/phases/V1_PHASE_14_DEFINITION.md's Acknowledgement
     * section). Never independently addressed by URL.
     *
     * @return HasMany<AnnouncementAcknowledgement, $this>
     */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(AnnouncementAcknowledgement::class);
    }
}
