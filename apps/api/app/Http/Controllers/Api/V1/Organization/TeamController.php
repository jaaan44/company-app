<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreTeamRequest;
use App\Http\Requests\Organization\UpdateTeamRequest;
use App\Http\Resources\TeamResource;
use App\Models\Department;
use App\Models\Team;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Teams (Phase 6 — Organization Structure). Read endpoints require
 * `organization.view`; writes require `organization.manage` — enforced by
 * route middleware (routes/api/v1.php), not here. Create/update/delete
 * are audited (Phase 21, DEC-044) with curated name/status/department
 * metadata only.
 */
class TeamController extends Controller
{
    /** @var array<int, string> */
    private const AUDITED_FIELDS = ['name', 'status', 'department_id'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', new Enum(OrganizationStatus::class)],
            'department' => ['sometimes', 'string'],
        ]);

        $teams = Team::query()
            ->with('department')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when(
                $request->filled('department'),
                fn ($query) => $query->where('department_id', $this->resolveDepartmentId($request->string('department')->toString()) ?? -1)
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return TeamResource::collection($teams);
    }

    public function store(StoreTeamRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['department_id'] = $this->resolveDepartmentId($data['department_id'] ?? null);

        $team = Team::create($data);
        $team->load('department');

        $this->auditLogger->recordForRequest(
            $request,
            AuditActions::TEAM_CREATED,
            entityType: 'Team',
            entityPublicId: $team->public_id,
            after: $this->curatedSnapshot($team),
        );

        return (new TeamResource($team))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Team $team): TeamResource
    {
        return new TeamResource($team->load('department'));
    }

    public function update(UpdateTeamRequest $request, Team $team): TeamResource
    {
        $team->load('department');
        $before = $this->curatedSnapshot($team);

        $data = $request->validated();

        if (array_key_exists('department_id', $data)) {
            $data['department_id'] = $this->resolveDepartmentId($data['department_id']);
        }

        $team->update($data);
        $team->load('department');

        [$changedFields, $curatedBefore, $curatedAfter] = AuditLogger::diff(
            $before,
            $this->curatedSnapshot($team),
            self::AUDITED_FIELDS,
        );

        if ($changedFields !== []) {
            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::TEAM_UPDATED,
                entityType: 'Team',
                entityPublicId: $team->public_id,
                changedFields: $changedFields,
                before: $curatedBefore,
                after: $curatedAfter,
            );
        }

        return new TeamResource($team);
    }

    /**
     * A team that still has any (Phase 7) staff assigned to it, or (Phase
     * 14) is still targeted by an announcement audience, cannot be
     * deleted — same relational-integrity protection as
     * DepartmentController::destroy, backed by the `staff.team_id`/
     * `announcement_teams.team_id` `restrictOnDelete()` foreign keys.
     */
    public function destroy(Request $request, Team $team): JsonResponse
    {
        if ($team->staff()->exists()) {
            return response()->json([
                'message' => 'This team still has staff assigned to it and cannot be deleted.',
            ], 409);
        }

        if ($team->announcements()->exists()) {
            return response()->json([
                'message' => 'This team is still targeted by an announcement audience and cannot be deleted.',
            ], 409);
        }

        $team->load('department');
        $publicId = $team->public_id;
        $before = $this->curatedSnapshot($team);

        $team->delete();

        $this->auditLogger->recordForRequest(
            $request,
            AuditActions::TEAM_DELETED,
            entityType: 'Team',
            entityPublicId: $publicId,
            before: $before,
        );

        return response()->json(status: 204);
    }

    private function resolveDepartmentId(?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return Department::query()->where('public_id', $publicId)->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function curatedSnapshot(Team $team): array
    {
        return [
            'name' => $team->name,
            'status' => $team->status->value,
            'department_id' => $team->department?->public_id,
        ];
    }
}
