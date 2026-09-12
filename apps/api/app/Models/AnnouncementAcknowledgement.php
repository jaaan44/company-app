<?php

namespace App\Models;

use Database\Factories\AnnouncementAcknowledgementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Staff member's acknowledgement of an Announcement (Phase 14) —
 * self-initiated, idempotent, never a mandatory-read/compliance record.
 * `created_at` is the acknowledgement timestamp itself — no separate
 * column. Deliberately no `public_id` — never independently addressed by
 * URL, only read as part of its parent Announcement's own resource shape.
 *
 * @property int $id
 * @property int $announcement_id
 * @property int $staff_id
 */
#[Fillable(['announcement_id', 'staff_id'])]
class AnnouncementAcknowledgement extends Model
{
    /** @use HasFactory<AnnouncementAcknowledgementFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Announcement, $this>
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
