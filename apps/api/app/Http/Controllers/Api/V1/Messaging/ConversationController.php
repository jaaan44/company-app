<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Enums\ConversationType;
use App\Http\Controllers\Api\V1\Messaging\Concerns\AuthorizesConversationAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\StoreDirectConversationRequest;
use App\Http\Requests\Messaging\StoreGroupConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Conversations — direct, group, and project (Phase 16). Every action
 * requires the authenticated User to have a linked Staff record
 * (AuthorizesConversationAccess::resolveAuthenticatedStaff) — Messaging
 * participants are identified by Staff, not User (diverging from
 * Notifications' DEC-038 choice). No permission gates any Messaging
 * endpoint: visibility is membership-only, enforced entirely in this
 * controller, so Administrator's usual Gate::before override never
 * grants implicit access (see AuthorizesConversationAccess).
 */
class ConversationController extends Controller
{
    use AuthorizesConversationAccess;

    /**
     * The authenticated Staff member's own conversations — direct,
     * group, and already-created project conversations (a project
     * conversation that hasn't been lazily created yet simply doesn't
     * appear here). Ordered by latest message activity, newest first —
     * derived via a MAX aggregate, never a cached "last activity" column.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $staff = $this->resolveAuthenticatedStaff($request);

        $conversations = Conversation::query()
            ->whereHas('members', fn ($query) => $query->where('staff_id', $staff->id))
            ->with(['members.staff', 'owner', 'project'])
            ->withMax('messages', 'id')
            ->orderByDesc('messages_max_id')
            ->paginate($request->integer('per_page', 50));

        return ConversationResource::collection($conversations);
    }

    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        $staff = $this->resolveAuthenticatedStaff($request);
        $this->authorizeMembership($staff, $conversation);

        return new ConversationResource($conversation->load(['members.staff', 'owner', 'project']));
    }

    /**
     * Finds-or-creates the one canonical direct conversation for the
     * authenticated Staff member and the given target — never creates a
     * duplicate for an existing pair. 200 if the conversation already
     * existed, 201 if it was just created.
     */
    public function storeDirect(StoreDirectConversationRequest $request): JsonResponse
    {
        $staff = $this->resolveAuthenticatedStaff($request);
        $targetStaffId = Staff::query()->where('public_id', $request->validated('staff_id'))->value('id');

        $existing = Conversation::query()
            ->where('type', ConversationType::Direct)
            ->whereHas('members', fn ($query) => $query->where('staff_id', $staff->id))
            ->whereHas('members', fn ($query) => $query->where('staff_id', $targetStaffId))
            ->first();

        if ($existing !== null) {
            return (new ConversationResource($existing->load(['members.staff', 'owner', 'project'])))
                ->response()
                ->setStatusCode(200);
        }

        $conversation = DB::transaction(function () use ($staff, $targetStaffId) {
            $conversation = Conversation::create(['type' => ConversationType::Direct]);

            $conversation->members()->createMany([
                ['staff_id' => $staff->id],
                ['staff_id' => $targetStaffId],
            ]);

            return $conversation;
        });

        return (new ConversationResource($conversation->load(['members.staff', 'owner', 'project'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Creates an ad-hoc, named group conversation. The authenticated
     * Staff member becomes its single owner and is always a member,
     * regardless of whether they were also listed in member_staff_ids.
     */
    public function storeGroup(StoreGroupConversationRequest $request): JsonResponse
    {
        $staff = $this->resolveAuthenticatedStaff($request);

        $memberStaffIds = Staff::query()
            ->whereIn('public_id', $request->validated('member_staff_ids'))
            ->pluck('id')
            ->push($staff->id)
            ->unique();

        $conversation = DB::transaction(function () use ($staff, $request, $memberStaffIds) {
            $conversation = Conversation::create([
                'type' => ConversationType::Group,
                'name' => $request->validated('name'),
                'owner_staff_id' => $staff->id,
            ]);

            $conversation->members()->createMany(
                $memberStaffIds->map(fn (int $id) => ['staff_id' => $id])->all(),
            );

            return $conversation;
        });

        return (new ConversationResource($conversation->load(['members.staff', 'owner', 'project'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Marks the conversation read, up to its latest message at the time
     * of the call, for the authenticated Staff member only. Idempotent —
     * calling again with no new messages simply re-sets the same value.
     */
    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $staff = $this->resolveAuthenticatedStaff($request);
        $this->authorizeMembership($staff, $conversation);

        $latestMessageId = $conversation->messages()->max('id');

        $conversation->members()->where('staff_id', $staff->id)->update([
            'last_read_message_id' => $latestMessageId,
        ]);

        return response()->json(['data' => ['unread_count' => 0]]);
    }
}
