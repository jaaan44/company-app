<?php

namespace App\Models;

use App\Enums\OperationalStatus;
use Database\Factories\StaffCheckInFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An explicit, user-triggered location check-in for a Staff member (Phase
 * 9, DEC-005/DEC-032) — "I am currently working/checking in from this
 * location." Never continuous/background GPS tracking. Append-only in
 * practice: ordinary users never update or delete a check-in; only an
 * Administrator may delete one as a correction (`location.manage`).
 * "Current location" is derived as the latest row for a given Staff (see
 * Staff::latestCheckIn()), not a separate mutable column.
 *
 * @property int $id
 * @property string $public_id
 * @property int $staff_id
 * @property string $latitude
 * @property string $longitude
 * @property int|null $accuracy_meters
 * @property string|null $location_label
 * @property string|null $note
 * @property OperationalStatus|null $status
 */
#[Fillable([
    'staff_id', 'latitude', 'longitude', 'accuracy_meters',
    'location_label', 'note', 'status',
])]
class StaffCheckIn extends Model
{
    /** @use HasFactory<StaffCheckInFactory> */
    use HasFactory;

    protected $table = 'staff_checkins';

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'status' => OperationalStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StaffCheckIn $checkIn): void {
            $checkIn->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
