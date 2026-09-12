<?php

namespace App\Enums;

/**
 * The three conversation shapes Phase 16 supports — deliberately closed,
 * no Team/Department-linked variant (not named by the governing roadmap
 * line). Never client-supplied directly: each type is set only by its
 * own dedicated creation path (ConversationController::storeDirect/
 * storeGroup, ProjectConversationController::storeOrShow), so an invalid
 * type/name/project_id/owner_staff_id combination never arises.
 */
enum ConversationType: string
{
    case Direct = 'direct';
    case Group = 'group';
    case Project = 'project';
}
