<?php

namespace App\Http\Controllers\Api\V1\Messaging\Concerns;

use App\Models\Project;

/**
 * Keeps a Project's conversation membership synchronized with its
 * Project Membership roster (Phase 16) — used by
 * ProjectMembershipController's store()/destroy() actions. A no-op
 * whenever the Project has no conversation yet (lazy creation: a
 * conversation is only created on first use of the project messaging
 * surface, never eagerly for every Project). Project conversation
 * membership is never independently managed — this is the only
 * mechanism that ever changes it after creation.
 */
trait SyncsProjectConversationMembership
{
    private function addToProjectConversationIfExists(Project $project, int $staffId): void
    {
        $conversation = $project->conversation()->first();

        $conversation?->members()->firstOrCreate(['staff_id' => $staffId]);
    }

    private function removeFromProjectConversationIfExists(Project $project, int $staffId): void
    {
        $conversation = $project->conversation()->first();

        $conversation?->members()->where('staff_id', $staffId)->delete();
    }
}
