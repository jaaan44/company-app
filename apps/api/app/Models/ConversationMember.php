<?php

namespace App\Models;

use Database\Factories\ConversationMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Staff member's current participation in a Conversation (Phase 16) —
 * the current roster only, mirroring ProjectMembership; no
 * `public_id` (never independently addressed by URL — addressed via its
 * Conversation's and Staff's `public_id` in a nested route).
 *
 * `last_read_message_id` is the sole read-position representation
 * (governing Phase 16 instructions: use the minimum non-redundant
 * representation) — unread state/count is always derived from it via
 * unreadCount(), never a separately stored/cached count.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $staff_id
 * @property int|null $last_read_message_id
 */
#[Fillable(['conversation_id', 'staff_id', 'last_read_message_id'])]
class ConversationMember extends Model
{
    /** @use HasFactory<ConversationMemberFactory> */
    use HasFactory;

    protected $table = 'conversation_members';

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_read_message_id');
    }

    /**
     * The number of messages in this conversation this member has not
     * yet read — always derived (message count with id greater than
     * last_read_message_id, or every message when nothing has been read
     * yet), never a stored/cached column.
     */
    public function unreadCount(): int
    {
        return Message::query()
            ->where('conversation_id', $this->conversation_id)
            ->when(
                $this->last_read_message_id !== null,
                fn ($query) => $query->where('id', '>', $this->last_read_message_id),
            )
            ->count();
    }
}
