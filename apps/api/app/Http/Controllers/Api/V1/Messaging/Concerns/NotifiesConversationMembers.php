<?php

namespace App\Http\Controllers\Api\V1\Messaging\Concerns;

use App\Enums\NotificationSourceType;
use App\Enums\NotificationType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Staff;

/**
 * Phase 16's second real Notification producer, mirroring
 * App\Http\Controllers\Api\V1\Announcements\Concerns\
 * NotifiesAnnouncementAudience's exact shape (synchronous, direct
 * Eloquent creation inside the sending request's own transaction — no
 * queue). Every newly sent message creates exactly one Notification for
 * every other *current* conversation member with a linked User account
 * — the sender never notifies themselves, and (per the governing Phase
 * 16 instructions) multiple message notifications for the same
 * conversation are never collapsed/deduplicated.
 *
 * Content is deliberately generic and fixed, regardless of conversation
 * type — never the message body, never the conversation/group/project
 * name. A client follows the notification's `source` (the conversation)
 * to that conversation's own, separately-authorized message list for the
 * actual content.
 */
trait NotifiesConversationMembers
{
    private const NOTIFICATION_TITLE = 'New message';

    private const NOTIFICATION_MESSAGE = 'You have a new message in one of your conversations.';

    private function notifyOtherMembers(Conversation $conversation, Message $message, Staff $sender): void
    {
        $recipientUserIds = $conversation->members()
            ->where('staff_id', '!=', $sender->id)
            ->whereHas('staff', fn ($query) => $query->whereNotNull('user_id'))
            ->with('staff:id,user_id')
            ->get()
            ->pluck('staff.user_id');

        foreach ($recipientUserIds as $recipientUserId) {
            Notification::create([
                'recipient_user_id' => $recipientUserId,
                'type' => NotificationType::MessageReceived,
                'title' => self::NOTIFICATION_TITLE,
                'message' => self::NOTIFICATION_MESSAGE,
                'source_type' => NotificationSourceType::Conversation,
                'source_public_id' => $conversation->public_id,
            ]);
        }
    }
}
