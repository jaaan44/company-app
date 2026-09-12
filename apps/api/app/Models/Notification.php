<?php

namespace App\Models;

use App\Enums\NotificationSourceType;
use App\Enums\NotificationType;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A system-generated, in-app, user-facing alert about an event elsewhere
 * in the application (Phase 15) — not messaging, chat, a social feed, or
 * arbitrary user-authored content; see docs/phases/
 * V1_PHASE_15_DEFINITION.md's Critical Domain Boundary. Recipient identity
 * is the User account itself (DEC-038), never Staff directly — a
 * Notification can only ever be consumed through an authenticated login.
 * Externally addressable via `public_id` (DEC-017); the internal numeric
 * id and recipient_user_id are never exposed.
 *
 * Created only by an internal application producer (currently: Announcement
 * publish — see AnnouncementController's NotifiesAnnouncementAudience),
 * never directly by a client request — there is no create/update/delete
 * API for this model at all beyond the self-service read-state actions.
 *
 * @property int $id
 * @property string $public_id
 * @property int $recipient_user_id
 * @property NotificationType $type
 * @property string $title
 * @property string $message
 * @property NotificationSourceType|null $source_type
 * @property string|null $source_public_id
 * @property Carbon|null $read_at
 */
#[Fillable(['recipient_user_id', 'type', 'title', 'message', 'source_type', 'source_public_id', 'read_at'])]
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'source_type' => NotificationSourceType::class,
            'read_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Notification $notification): void {
            $notification->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
