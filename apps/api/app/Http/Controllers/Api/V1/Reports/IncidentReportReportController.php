<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Enums\IncidentReportStatus;
use App\Enums\IncidentReportType;
use App\Enums\IncidentSeverity;
use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentReportResource;
use App\Models\Client;
use App\Models\IncidentReport;
use App\Models\Project;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Reporting\IncidentReportVisibility;
use App\Support\Reporting\CsvExport;
use App\Support\Reporting\PublicIdResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Incident Reports report (Phase 20 — Reports, DEC-043). No
 * `can:<permission>` route middleware — visibility is scoped
 * in-controller via App\Services\Reporting\IncidentReportVisibility, the
 * exact rule IncidentReportController's own
 * `GET /api/v1/incident-reports` already enforces. Phase 19's
 * deliberately narrow visibility (reporter, assigned investigator,
 * participants, reporter's current Manager, Administrator — NO
 * Project-Lead carve-out, DEC-042) is preserved exactly; this phase
 * never broadens it, in JSON, CSV, or the Dashboard's aggregate counts.
 */
class IncidentReportReportController extends Controller
{
    private const WITH_RELATIONS = ['client', 'project', 'task', 'reporter', 'assignedTo', 'participants'];

    public function __construct(private readonly IncidentReportVisibility $visibility) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return IncidentReportResource::collection($this->filteredQuery($request)->paginate($request->integer('per_page', 50)));
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filteredQuery($request)->cursor()->map(fn (IncidentReport $report) => [
            $report->public_id,
            $report->client?->name,
            $report->project?->name,
            $report->task?->title,
            $report->reporter?->displayName(),
            $report->assignedTo?->displayName(),
            $report->occurred_at->toIso8601String(),
            $report->incident_type->value,
            $report->severity->value,
            $report->status->value,
            $report->description,
        ]);

        return CsvExport::stream('incident-reports.csv', [
            'Public ID', 'Client', 'Project', 'Task', 'Reporter', 'Assigned Investigator',
            'Occurred At', 'Incident Type', 'Severity', 'Status', 'Description',
        ], $rows);
    }

    /**
     * @return Builder<IncidentReport>
     */
    private function filteredQuery(Request $request): Builder
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'client' => ['sometimes', 'string'],
            'project' => ['sometimes', 'string'],
            'task' => ['sometimes', 'string'],
            'reporter' => ['sometimes', 'string'],
            'assigned' => ['sometimes', 'string'],
            'status' => ['sometimes', new Enum(IncidentReportStatus::class)],
            'severity' => ['sometimes', new Enum(IncidentSeverity::class)],
            'incident_type' => ['sometimes', new Enum(IncidentReportType::class)],
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
                $request->filled('reporter'),
                fn ($query) => $query->where('reporter_staff_id', PublicIdResolver::resolve(Staff::class, $request->string('reporter')->toString()) ?? -1)
            )
            ->when(
                $request->filled('assigned'),
                fn ($query) => $query->where('assigned_to_staff_id', PublicIdResolver::resolve(Staff::class, $request->string('assigned')->toString()) ?? -1)
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('severity'), fn ($query) => $query->where('severity', $request->string('severity')))
            ->when($request->filled('incident_type'), fn ($query) => $query->where('incident_type', $request->string('incident_type')))
            ->when($request->filled('from'), fn ($query) => $query->where('occurred_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->where('occurred_at', '<=', $request->date('to')))
            ->orderByDesc('occurred_at');
    }
}
