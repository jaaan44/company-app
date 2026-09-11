<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreTeamRequest;
use App\Http\Requests\Organization\UpdateTeamRequest;
use App\Http\Resources\TeamResource;
use App\Models\Department;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Teams (Phase 6 — Organization Structure). Read endpoints require
 * `organization.view`; writes require `organization.manage` — enforced by
 * route middleware (routes/api/v1.php), not here.
 */
class TeamController extends Controller
{
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

        return (new TeamResource($team->load('department')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Team $team): TeamResource
    {
        return new TeamResource($team->load('department'));
    }

    public function update(UpdateTeamRequest $request, Team $team): TeamResource
    {
        $data = $request->validated();

        if (array_key_exists('department_id', $data)) {
            $data['department_id'] = $this->resolveDepartmentId($data['department_id']);
        }

        $team->update($data);

        return new TeamResource($team->load('department'));
    }

    public function destroy(Team $team): JsonResponse
    {
        $team->delete();

        return response()->json(status: 204);
    }

    private function resolveDepartmentId(?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return Department::query()->where('public_id', $publicId)->value('id');
    }
}
