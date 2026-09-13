<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Services\Reporting\LeaveRequestVisibility;
use App\Support\Reporting\CsvExport;
use App\Support\Reporting\PublicIdResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Leave Requests report (Phase 20 — Reports, DEC-043). No
 * `can:<permission>` route middleware — visibility is scoped
 * in-controller via App\Services\Reporting\LeaveRequestVisibility, the
 * exact rule LeaveRequestController's own supervisory
 * `GET /api/v1/leave-requests` surface already enforces (plus a
 * plain-Staff own-records fallback — see that class).
 */
class LeaveRequestReportController extends Controller
{
    private const WITH_RELATIONS = ['staff', 'leaveType', 'creator.staff'];

    public function __construct(private readonly LeaveRequestVisibility $visibility) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return LeaveRequestResource::collection($this->filteredQuery($request)->paginate($request->integer('per_page', 50)));
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filteredQuery($request)->cursor()->map(fn (LeaveRequest $leaveRequest) => [
            $leaveRequest->public_id,
            $leaveRequest->staff?->public_id,
            $leaveRequest->staff?->displayName(),
            $leaveRequest->leaveType?->name,
            $leaveRequest->start_date->toDateString(),
            $leaveRequest->end_date->toDateString(),
            $leaveRequest->total_days,
            $leaveRequest->status->value,
            $leaveRequest->reason,
        ]);

        return CsvExport::stream('leave-requests.csv', [
            'Public ID', 'Staff Public ID', 'Staff Name', 'Leave Type',
            'Start Date', 'End Date', 'Total Days', 'Status', 'Reason',
        ], $rows);
    }

    /**
     * @return Builder<LeaveRequest>
     */
    private function filteredQuery(Request $request): Builder
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'staff' => ['sometimes', 'string'],
            'type' => ['sometimes', 'string'],
            'status' => ['sometimes', 'string'],
        ]);

        return $this->visibility->visibleQuery($request)
            ->with(self::WITH_RELATIONS)
            ->when(
                $request->filled('staff'),
                fn ($query) => $query->where('staff_id', PublicIdResolver::resolve(Staff::class, $request->string('staff')->toString()) ?? -1)
            )
            ->when(
                $request->filled('type'),
                fn ($query) => $query->where('leave_type_id', PublicIdResolver::resolve(LeaveType::class, $request->string('type')->toString()) ?? -1)
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('end_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('start_date', '<=', $request->date('to')))
            ->orderByDesc('start_date');
    }
}
