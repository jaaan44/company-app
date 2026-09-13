<?php

namespace App\Services\Reporting;

use Illuminate\Http\Request;

/**
 * Staff Directory visibility for Dashboard/Reports (Phase 20) — reuses
 * the exact `staff.view` permission check StaffController's own routes
 * already gate reads with (04_API_CONVENTIONS.md/05_SECURITY_MODEL.md):
 * the Staff Directory is company-wide for any holder (Administrator,
 * Manager, and Staff all receive it by default, RolePermissionSeeder),
 * so there is no further row-level scoping to reproduce here. A
 * requester without it (an edge case — a User with no role assigned at
 * all) sees nothing, never a default company-wide fallback.
 */
final class StaffVisibility
{
    public function canView(Request $request): bool
    {
        return $request->user()?->can('staff.view') ?? false;
    }
}
