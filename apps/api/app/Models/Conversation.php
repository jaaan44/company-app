<?php

namespace App\Models;

use App\Enums\ConversationType;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A direct, group, or project conversation (Phase 16) — deliberately
 * lightweight: no Team/Department-linked variant, no archive/mute, no
 * search. Participants are identified by Staff, not User (diverging from
 * Notifications' DEC-038 choice — see docs/phases/
 * V1_PHASE_16_DEFINITION.md's Participant Identity section): a
 * conversation is something colleagues have with each other as
 * organizational people, chosen from the Staff Directory, not a raw
 * User list.
 *
 * `type` is never client-supplied — each type is created only through
 * its own dedicated path (ConversationController::storeDirect/
 * storeGroup, ProjectConversationController::storeOrShow), so an invalid
 * type/name/project_id/owner_staff_id combination never arises:
 * - Direct: `name`/`project_id`/`owner_staff_id` all null; always
 *   exactly two members, fixed at creation.
 * - Group: `name` required, `owner_staff_id` required (the single
 *   distinguished owner — no co-owners/moderators); `project_id` null.
 * - Project: `project_id` required (unique — one conversation per
 *   Project, lazily created on first use); `name`/`owner_staff_id` null
 *   — membership is derived exclusively from Project Membership.
 *
 * @property int $id
 * @property string $public_id
 * @property ConversationType $type
 * @property string|null $name
 * @property int|null $project_id
 * @property int|null $owner_staff_id
 */
#[Fillable(['type', 'name', 'project_id', 'owner_staff_id'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Conversation $conversation): void {
            $conversation->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The group conversation's single owner. Null for direct/project
     * conversations.
     *
     * @return BelongsTo<Staff, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'owner_staff_id');
    }

    /**
     * The current roster — no historical-period tracking, mirroring
     * ProjectMembership.
     *
     * @return HasMany<ConversationMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class);
    }

    /**
     * Full message history, oldest to newest — retained indefinitely,
     * immutable after sending.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The most recent message, if any — used to order the conversation
     * list by latest activity (derived, not a cached column).
     *
     * @return HasOne<Message, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }
}
