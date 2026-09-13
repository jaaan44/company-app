<?php

namespace App\Services\Reporting;

use Illuminate\Http\Request;

/**
 * Client directory visibility for Dashboard/Reports (Phase 20) — mirrors
 * StaffVisibility exactly: `clients.view` is company-wide (Administrator/
 * Manager/Staff), so no further row-level scoping exists to reproduce.
 */
final class ClientVisibility
{
    public function canView(Request $request): bool
    {
        return $request->user()?->can('clients.view') ?? false;
    }
}
