<?php

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Enums\ConversationType;
use App\Http\Controllers\Api\V1\Messaging\Concerns\AuthorizesConversationAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The lazy get-or-create entry point for a Project's single conversation
 * (Phase 16) — no conversation row exists for a Project until this is
 * called for the first time by a current Project member. Membership is
 * derived exclusively from the Project's current Project Membership
 * roster (Phase 10) at creation time, and kept in sync afterward only by
 * ProjectMembershipController (SyncsProjectConversationMembership) — a
 * project conversation never has an independently managed roster.
 * Visibility is strictly "current Project member," never extended to
 * `projects.view` holders (Administrator/Manager) who aren't themselves
 * a member — Messaging privacy has no permission-based override.
 */
class ProjectConversationController extends Controller
{
    use AuthorizesConversationAccess;

    public function storeOrShow(Request $request, Project $project): ConversationResource
    {
        $staff = $this->resolveAuthenticatedStaff($request);

        if (! $project->memberships()->where('staff_id', $staff->id)->exists()) {
            abort(404);
        }

        $conversation = $project->conversation()->first();

        if ($conversation === null) {
            $conversation = $this->createConversation($project);
        }

        return new ConversationResource($conversation->load(['members.staff', 'owner', 'project']));
    }

    private function createConversation(Project $project): Conversation
    {
        try {
            return DB::transaction(function () use ($project) {
                $conversation = Conversation::create([
                    'type' => ConversationType::Project,
                    'project_id' => $project->id,
                ]);

                $memberStaffIds = $project->memberships()->pluck('staff_id');

                $conversation->members()->createMany(
                    $memberStaffIds->map(fn (int $id) => ['staff_id' => $id])->all(),
                );

                return $conversation;
            });
        } catch (QueryException) {
            // Another request won the race to create this Project's
            // conversation first (the unique index on
            // conversations.project_id rejected the duplicate insert) —
            // simply return the one that now exists.
            return $project->conversation()->firstOrFail();
        }
    }
}
