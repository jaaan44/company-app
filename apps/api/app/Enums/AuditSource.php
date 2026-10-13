<?php

namespace App\Enums;

/**
 * Which front door an audited action came through (Phase 21, DEC-044):
 * the Sanctum/mobile API, the Admin Backoffice, or (Phase 34A, DEC-063)
 * the browser: the shared web login and the Staff Web Portal, whose
 * screens call the API in-process (App\Services\Portal\InternalApi).
 */
enum AuditSource: string
{
    case Api = 'api';
    case Admin = 'admin';
    case Web = 'web';
}
