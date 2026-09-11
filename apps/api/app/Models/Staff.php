<?php

namespace App\Models;

use App\Enums\StaffStatus;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The company personnel record (Phase 7) — the canonical staff/employee
 * directory and employment-profile foundation later modules reference.
 * Deliberately separate from `User` (the authentication/account model):
 * a Staff record may exist with no login access, and a User may exist
 * with no Staff record. See DEC-030.
 *
 * @property int $id
 * @property string $public_id
 * @property string $employee_number
 * @property string $first_name
 * @property string $last_name
 * @property string|null $preferred_name
 * @property string|null $company_email
 * @property string|null $company_phone
 * @property StaffStatus $status
 * @property Carbon|null $hire_date
 * @property Carbon|null $separation_date
 * @property int|null $department_id
 * @property int|null $team_id
 * @property int|null $position_id
 * @property int|null $manager_id
 * @property int|null $user_id
 */
#[Fillable([
    'employee_number', 'first_name', 'last_name', 'preferred_name',
    'company_email', 'company_phone', 'status', 'hire_date', 'separation_date',
    'department_id', 'team_id', 'position_id', 'manager_id', 'user_id',
])]
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory;

    protected $table = 'staff';

    /**
     * Bounded depth for the manager-chain walk used to reject reporting
     * cycles (see wouldCreateCycleWith()) — a real ~100-person org's
     * reporting depth never comes close to this. Deliberately not
     * unbounded general-purpose cycle detection.
     */
    private const MAX_MANAGER_CHAIN_DEPTH = 50;

    protected function casts(): array
    {
        return [
            'status' => StaffStatus::class,
            'hire_date' => 'date',
            'separation_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Staff $staff): void {
            $staff->public_id ??= (string) Str::ulid();

            // The migration's DB-level default isn't reflected on this
            // in-memory instance after an insert unless refreshed — see
            // the identical note on Department/Team/Position.
            $staff->status ??= StaffStatus::Active;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function displayName(): string
    {
        return $this->preferred_name ?: "{$this->first_name} {$this->last_name}";
    }

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'manager_id');
    }

    /**
     * Staff who report directly to this staff member.
     *
     * @return HasMany<Staff, $this>
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(Staff::class, 'manager_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether assigning $proposedManagerId as this staff member's manager
     * would create a reporting cycle — i.e. $proposedManagerId's own
     * manager chain eventually loops back to this staff member. Walks a
     * bounded number of steps (MAX_MANAGER_CHAIN_DEPTH); a real ~100-person
     * org's reporting depth never comes close to it, so this is a
     * practical, maintainable guard rather than general graph-cycle
     * detection. Self-assignment ($proposedManagerId === $this->id) is
     * handled separately by the caller (it isn't a "chain" at all).
     */
    public function wouldCreateCycleWith(int $proposedManagerId): bool
    {
        $currentId = $proposedManagerId;

        for ($i = 0; $i < self::MAX_MANAGER_CHAIN_DEPTH; $i++) {
            if ($currentId === $this->id) {
                return true;
            }

            $currentId = self::query()->where('id', $currentId)->value('manager_id');

            if ($currentId === null) {
                return false;
            }
        }

        return true;
    }
}
