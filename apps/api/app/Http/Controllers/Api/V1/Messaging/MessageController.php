<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Http\Controllers\Api\V1\Messaging\Concerns\AuthorizesConversationAccess;
use App\Http\Controllers\Api\V1\Messaging\Concerns\NotifiesConversationMembers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Messages within a conversation (Phase 16). Plain text only, immutable
 * after sending — there is no update or delete route for a message
 * anywhere in this API. Membership-only authorization: a non-member gets
 * 404, never a partial/redacted view.
 */
class MessageController extends Controller
{
    use AuthorizesConversationAccess, NotifiesConversationMembers;

    /**
     * Paginated, newest-first — matches this API's established listing
     * convention (e.g. Notifications).
     */
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $staff = $this->resolveAuthenticatedStaff($request);
        $this->authorizeMembership($staff, $conversation);

        $messages = $conversation->messages()
            ->with('sender')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50));

        return MessageResource::collection($messages);
    }

    /**
     * Sends a message, marks it as read for the sender (they have
     * obviously read their own message), and fans out exactly one
     * Notification to every other current member with a linked User
     * account — synchronously, inside the same transaction, mirroring
     * NotifiesAnnouncementAudience's established pattern.
     */
    public function store(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $staff = $this->resolveAuthenticatedStaff($request);
        $this->authorizeMembership($staff, $conversation);

        $message = DB::transaction(function () use ($conversation, $staff, $request) {
            $message = $conversation->messages()->create([
                'sender_staff_id' => $staff->id,
                'body' => $request->validated('body'),
            ]);

            $conversation->members()->where('staff_id', $staff->id)->update([
                'last_read_message_id' => $message->id,
            ]);

            $this->notifyOtherMembers($conversation, $message, $staff);

            return $message;
        });

        return (new MessageResource($message->load('sender')))
            ->response()
            ->setStatusCode(201);
    }
}
