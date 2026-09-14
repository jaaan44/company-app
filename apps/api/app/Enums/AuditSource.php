<?php

namespace App\Enums;

/**
 * Which front door an audited action came through (Phase 21, DEC-044).
 * This API has exactly two — Sanctum/mobile and the session-based Admin
 * Backoffice (05_SECURITY_MODEL.md §Authentication) — no third value is
 * meaningful in V1.
 */
enum AuditSource: string
{
    case Api = 'api';
    case Admin = 'admin';
}
