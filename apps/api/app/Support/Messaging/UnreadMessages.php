<?php

namespace App\Support\Messaging;

use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * How many messages a staff member hasn't read, across every conversation
 * they belong to: those after their last-read message (Phase 16 read
 * state). One definition for Home (`messages.unread_count`, Phase 27) and
 * `GET /api/v1/conversations/unread-count` (Phase 37B, DEC-068).
 */
final class UnreadMessages
{
    private function __construct() {}

    public static function countFor(Staff $staff): int
    {
        return DB::table('messages')
            ->join('conversation_members', 'conversation_members.conversation_id', '=', 'messages.conversation_id')
            ->where('conversation_members.staff_id', $staff->id)
            ->where(function ($query) {
                $query->whereNull('conversation_members.last_read_message_id')
                    ->orWhereColumn('messages.id', '>', 'conversation_members.last_read_message_id');
            })
            ->count();
    }
}
