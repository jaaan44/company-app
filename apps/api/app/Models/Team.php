<?php

namespace App\Models;

use App\Enums\OrganizationStatus;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A team/group within the organization structure (Phase 6). Belongs to at
 * most one Department, or none yet (DEC-029 — teams do not span multiple
 * departments). Externally addressable via `public_id` (DEC-017).
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $department_id
 * @property string $name
 * @property string|null $description
 * @property OrganizationStatus $status
 * @property int $sort_order
 */
#[Fillable(['department_id', 'name', 'description', 'status', 'sort_order'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Team $team): void {
            $team->public_id ??= (string) Str::ulid();

            // See Department::booted() — the DB-level defaults aren't
            // reflected on this in-memory instance after an insert unless
            // refreshed.
            $team->status ??= OrganizationStatus::Active;
            $team->sort_order ??= 0;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
