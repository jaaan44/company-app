<?php

namespace App\Models;

use App\Enums\OrganizationStatus;
use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An organizational/job position (Phase 6). Standalone master data in this
 * phase — not yet associated with any staff record (Phase 7 introduces
 * Staff). May optionally belong to a Department, or exist independently
 * per the governing Phase 6 instructions. Externally addressable via
 * `public_id` (DEC-017).
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $department_id
 * @property string $title
 * @property string|null $description
 * @property OrganizationStatus $status
 * @property int $sort_order
 */
#[Fillable(['department_id', 'title', 'description', 'status', 'sort_order'])]
class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Position $position): void {
            $position->public_id ??= (string) Str::ulid();

            // See Department::booted() — the DB-level defaults aren't
            // reflected on this in-memory instance after an insert unless
            // refreshed.
            $position->status ??= OrganizationStatus::Active;
            $position->sort_order ??= 0;
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

    /**
     * @return HasMany<Staff, $this>
     */
    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }
}
