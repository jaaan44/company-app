<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single plain-text message within a Conversation (Phase 16).
 * Immutable after sending — no editing, no soft/hard deletion, no
 * mutation endpoint of any kind exists anywhere in this API. Message
 * history is retained indefinitely; ordering is exact via the
 * auto-increment `id` (never a separate sequence/position column).
 *
 * @property int $id
 * @property string $public_id
 * @property int $conversation_id
 * @property int $sender_staff_id
 * @property string $body
 */
#[Fillable(['conversation_id', 'sender_staff_id', 'body'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Message $message): void {
            $message->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The Staff member who sent this message. restrictOnDelete on
     * messages.sender_staff_id backs the deletion guard in
     * StaffController::destroy — message history (including its
     * author) is preserved indefinitely.
     *
     * @return BelongsTo<Staff, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'sender_staff_id');
    }
}
