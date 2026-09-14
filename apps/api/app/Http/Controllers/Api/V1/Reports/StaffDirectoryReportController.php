<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Enums\StaffStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use App\Models\Department;
use App\Models\Staff;
use App\Models\Team;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Reporting\CsvExport;
use App\Support\Reporting\PublicIdResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff Directory report (Phase 20 — Reports, DEC-043). Gated by the
 * existing `staff.view` permission (route middleware) — identical
 * visibility to `GET /api/v1/staff` itself (StaffController), since the
 * Staff Directory is company-wide for any holder; this report never
 * widens that. Reuses StaffResource for the JSON shape rather than
 * inventing an incompatible report row format.
 */
class StaffDirectoryReportController extends Controller
{
    private const WITH_RELATIONS = ['department', 'team', 'position', 'manager', 'latestOperationalStatus', 'user'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return StaffResource::collection($this->filteredQuery($request)->paginate($request->integer('per_page', 50)));
    }

    /**
     * Streams every currently-filtered, visible Staff row as CSV — same
     * filters, same visibility (staff.view, route-gated) as index().
     * Every export is audited (Phase 21, DEC-044) — the exported rows/
     * filters themselves are never captured, only that this report was
     * exported and by whom.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->auditLogger->recordForRequest(
            $request,
            AuditActions::REPORT_EXPORTED,
            entityType: 'Report',
            entityPublicId: null,
            after: ['report' => 'staff'],
        );

        $rows = $this->filteredQuery($request)->cursor()->map(fn (Staff $staff) => [
            $staff->public_id,
            $staff->employee_number,
            $staff->first_name,
            $staff->last_name,
            $staff->status->value,
            $staff->department?->name,
            $staff->team?->name,
            $staff->position?->title,
            $staff->manager?->displayName(),
            $staff->hire_date?->toDateString(),
        ]);

        return CsvExport::stream('staff-directory.csv', [
            'Public ID', 'Employee Number', 'First Name', 'Last Name', 'Status',
            'Department', 'Team', 'Position', 'Manager', 'Hire Date',
        ], $rows);
    }

    /**
     * @return Builder<Staff>
     */
    private function filteredQuery(Request $request): Builder
    {
        $request->validate([
            'department' => ['sometimes', 'string'],
            'team' => ['sometimes', 'string'],
            'status' => ['sometimes', new Enum(StaffStatus::class)],
        ]);

        return Staff::query()
            ->with(self::WITH_RELATIONS)
            ->when(
                $request->filled('department'),
                fn ($query) => $query->where('department_id', PublicIdResolver::resolve(Department::class, $request->string('department')->toString()) ?? -1)
            )
            ->when(
                $request->filled('team'),
                fn ($query) => $query->where('team_id', PublicIdResolver::resolve(Team::class, $request->string('team')->toString()) ?? -1)
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('last_name')
            ->orderBy('first_name');
    }
}
