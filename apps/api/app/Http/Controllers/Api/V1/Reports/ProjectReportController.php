<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Client;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Reporting\ProjectVisibility;
use App\Support\Audit\AuditActions;
use App\Support\Reporting\CsvExport;
use App\Support\Reporting\PublicIdResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Projects report (Phase 20 — Reports, DEC-043). No `can:<permission>`
 * route middleware — visibility is scoped in-controller via
 * App\Services\Reporting\ProjectVisibility, the exact rule
 * ProjectController's own `GET /api/v1/projects` already enforces
 * (`projects.view` for Administrator/Manager; an ordinary Staff member
 * scoped to Projects they hold a membership on).
 */
class ProjectReportController extends Controller
{
    public function __construct(
        private readonly ProjectVisibility $visibility,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ProjectResource::collection(
            $this->filteredQuery($request)->withCount('memberships')->paginate($request->integer('per_page', 50))
        );
    }

    public function export(Request $request): StreamedResponse
    {
        $this->auditLogger->recordForRequest(
            $request,
            AuditActions::REPORT_EXPORTED,
            entityType: 'Report',
            entityPublicId: null,
            after: ['report' => 'projects'],
        );

        $rows = $this->filteredQuery($request)->cursor()->map(fn (Project $project) => [
            $project->public_id,
            $project->project_code,
            $project->name,
            $project->status->value,
            $project->client?->name,
            $project->start_date?->toDateString(),
            $project->target_end_date?->toDateString(),
            $project->completed_date?->toDateString(),
        ]);

        return CsvExport::stream('projects.csv', [
            'Public ID', 'Project Code', 'Name', 'Status', 'Client',
            'Start Date', 'Target End Date', 'Completed Date',
        ], $rows);
    }

    /**
     * @return Builder<Project>
     */
    private function filteredQuery(Request $request): Builder
    {
        $request->validate([
            'client' => ['sometimes', 'string'],
            'status' => ['sometimes', new Enum(ProjectStatus::class)],
        ]);

        return $this->visibility->visibleQuery($request)
            ->with('client')
            ->when(
                $request->filled('client'),
                fn ($query) => $query->where('client_id', PublicIdResolver::resolve(Client::class, $request->string('client')->toString()) ?? -1)
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('name');
    }
}
