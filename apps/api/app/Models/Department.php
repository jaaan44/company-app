<?php

namespace App\Models;

use App\Enums\OrganizationStatus;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A major organizational unit (Phase 6 — Organization Structure). Flat,
 * single-level — no sub-department hierarchy (see DEC-029 and
 * docs/phases/V1_PHASE_06_DEFINITION.md). Externally addressable via
 * `public_id` (DEC-017); the internal numeric id is never exposed.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string|null $description
 * @property OrganizationStatus $status
 * @property int $sort_order
 */
#[Fillable(['name', 'description', 'status', 'sort_order'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Department $department): void {
            $department->public_id ??= (string) Str::ulid();

            // The migration's DB-level defaults aren't reflected on this
            // in-memory instance after an insert unless refreshed — set
            // them explicitly so a freshly created() model (e.g. the one
            // returned to DepartmentController::store) is immediately
            // usable/serializable without a round-trip.
            $department->status ??= OrganizationStatus::Active;
            $department->sort_order ??= 0;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * @return HasMany<Position, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * @return HasMany<Staff, $this>
     */
    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }
}
