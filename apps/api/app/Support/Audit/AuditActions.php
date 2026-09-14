<?php

namespace App\Support\Audit;

/**
 * The complete, stable catalog of general Audit Log action names (Phase
 * 21, DEC-044) — every `AuditLogger::record()`/`recordForRequest()` call
 * anywhere in the application uses one of these constants, never a
 * literal string and never a name derived from a PHP class/method name
 * (a refactor must never silently rename a historical action). This is
 * the one place a new stable name is added if a future phase authorizes
 * a new audited event — see docs/DECISIONS.md DEC-044 for the full,
 * deliberately curated V1 event list and its explicit exclusions.
 */
final class AuditActions
{
    private function __construct() {}

    // Authentication — both front doors (API/Sanctum and Admin
    // Backoffice/session) share the same action names; App\Enums\
    // AuditSource's `source` column distinguishes which one.
    public const string AUTH_LOGIN_SUCCEEDED = 'auth.login_succeeded';

    public const string AUTH_LOGIN_FAILED = 'auth.login_failed';

    public const string AUTH_LOGOUT = 'auth.logout';

    // Staff (Phase 7)
    public const string STAFF_CREATED = 'staff.created';

    public const string STAFF_UPDATED = 'staff.updated';

    public const string STAFF_SEPARATED = 'staff.separated';

    // Organization Structure (Phase 6)
    public const string DEPARTMENT_CREATED = 'organization.department.created';

    public const string DEPARTMENT_UPDATED = 'organization.department.updated';

    public const string DEPARTMENT_DELETED = 'organization.department.deleted';

    public const string TEAM_CREATED = 'organization.team.created';

    public const string TEAM_UPDATED = 'organization.team.updated';

    public const string TEAM_DELETED = 'organization.team.deleted';

    public const string POSITION_CREATED = 'organization.position.created';

    public const string POSITION_UPDATED = 'organization.position.updated';

    public const string POSITION_DELETED = 'organization.position.deleted';

    // Clients & Contacts (Phase 8)
    public const string CLIENT_CREATED = 'client.created';

    public const string CLIENT_UPDATED = 'client.updated';

    public const string CLIENT_DELETED = 'client.deleted';

    public const string CONTACT_CREATED = 'contact.created';

    public const string CONTACT_UPDATED = 'contact.updated';

    public const string CONTACT_DELETED = 'contact.deleted';

    // Projects & Project Membership (Phase 10)
    public const string PROJECT_CREATED = 'project.created';

    public const string PROJECT_UPDATED = 'project.updated';

    public const string PROJECT_DELETED = 'project.deleted';

    public const string PROJECT_MEMBERSHIP_ADDED = 'project.membership_added';

    public const string PROJECT_MEMBERSHIP_REMOVED = 'project.membership_removed';

    public const string PROJECT_MEMBERSHIP_ROLE_CHANGED = 'project.membership_role_changed';

    // Tasks (Phase 11) — deletion only; see DEC-044.
    public const string TASK_DELETED = 'task.deleted';

    // Announcements (Phase 14)
    public const string ANNOUNCEMENT_PUBLISHED = 'announcement.published';

    public const string ANNOUNCEMENT_ARCHIVED = 'announcement.archived';

    // Attachments (Phase 18/19) — upload/delete only, never download.
    public const string ATTACHMENT_UPLOADED = 'attachment.uploaded';

    public const string ATTACHMENT_DELETED = 'attachment.deleted';

    // Admin Dashboard & Reporting exports (Phase 20) and the Audit Log's
    // own export (Phase 21) — a distinct name so exporting the Audit Log
    // itself is never confused with exporting a business report.
    public const string REPORT_EXPORTED = 'report.exported';

    public const string AUDIT_LOG_EXPORTED = 'audit_log.exported';
}
