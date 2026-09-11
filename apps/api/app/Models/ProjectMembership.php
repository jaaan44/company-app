<?php

namespace App\Models;

use App\Enums\ProjectMembershipRole;
use Database\Factories\ProjectMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Staff member's current participation in a Project (Phase 10),
 * carrying a Project-scoped role — deliberately distinct from the global
 * Administrator/Manager/Staff application role (`App\Models\Role`). No
 * `public_id` — never independently addressed by URL; addressed via its
 * Project's and Staff's `public_id` (nested route). Represents the
 * *current* roster only, not a history table — see
 * docs/phases/V1_PHASE_10_DEFINITION.md.
 *
 * @property int $id
 * @property int $project_id
 * @property int $staff_id
 * @property ProjectMembershipRole $role
 */
#[Fillable(['project_id', 'staff_id', 'role'])]
class ProjectMembership extends Model
{
    /** @use HasFactory<ProjectMembershipFactory> */
    use HasFactory;

    protected $table = 'project_memberships';

    protected function casts(): array
    {
        return [
            'role' => ProjectMembershipRole::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProjectMembership $membership): void {
            $membership->role ??= ProjectMembershipRole::Member;
        });
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
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
