<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Enums\ServiceReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceReportResource;
use App\Models\Client;
use App\Models\Project;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Reporting\ServiceReportVisibility;
use App\Support\Reporting\CsvExport;
use App\Support\Reporting\PublicIdResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Service Reports report (Phase 20 — Reports, DEC-043). No
 * `can:<permission>` route middleware — visibility is scoped
 * in-controller via App\Services\Reporting\ServiceReportVisibility, the
 * exact rule ServiceReportController's own `GET /api/v1/service-reports`
 * already enforces. Phase 18's visibility (creator, participants,
 * creator's current Manager, linked Project's Project Lead,
 * Administrator) is preserved exactly — this phase never broadens it.
 */
class ServiceReportReportController extends Controller
{
    private const WITH_RELATIONS = ['client', 'project', 'task', 'creator', 'participants'];

    public function __construct(private readonly ServiceReportVisibility $visibility) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ServiceReportResource::collection($this->filteredQuery($request)->paginate($request->integer('per_page', 50)));
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filteredQuery($request)->cursor()->map(fn (ServiceReport $report) => [
            $report->public_id,
            $report->client?->name,
            $report->project?->name,
            $report->task?->title,
            $report->creator?->displayName(),
            $report->service_date->toDateString(),
            $report->status->value,
            $report->work_performed,
        ]);

        return CsvExport::stream('service-reports.csv', [
            'Public ID', 'Client', 'Project', 'Task', 'Creator', 'Service Date', 'Status', 'Work Performed',
        ], $rows);
    }

    /**
     * @return Builder<ServiceReport>
     */
    private function filteredQuery(Request $request): Builder
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'client' => ['sometimes', 'string'],
            'project' => ['sometimes', 'string'],
            'task' => ['sometimes', 'string'],
            'staff' => ['sometimes', 'string'],
            'status' => ['sometimes', new Enum(ServiceReportStatus::class)],
        ]);

        return $this->visibility->visibleQuery($request)
            ->with(self::WITH_RELATIONS)
            ->when(
                $request->filled('client'),
                fn ($query) => $query->where('client_id', PublicIdResolver::resolve(Client::class, $request->string('client')->toString()) ?? -1)
            )
            ->when(
                $request->filled('project'),
                fn ($query) => $query->where('project_id', PublicIdResolver::resolve(Project::class, $request->string('project')->toString()) ?? -1)
            )
            ->when(
                $request->filled('task'),
                fn ($query) => $query->where('task_id', PublicIdResolver::resolve(Task::class, $request->string('task')->toString()) ?? -1)
            )
            ->when(
                $request->filled('staff'),
                fn ($query) => $query->where('creator_staff_id', PublicIdResolver::resolve(Staff::class, $request->string('staff')->toString()) ?? -1)
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('service_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('service_date', '<=', $request->date('to')))
            ->orderByDesc('service_date');
    }
}
