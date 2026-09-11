<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StorePositionRequest;
use App\Http\Requests\Organization\UpdatePositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\Department;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Positions (Phase 6 — Organization Structure). Standalone organizational
 * master data — not yet associated with any staff record (Phase 7). Read
 * endpoints require `organization.view`; writes require
 * `organization.manage` — enforced by route middleware
 * (routes/api/v1.php), not here.
 */
class PositionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', new Enum(OrganizationStatus::class)],
            'department' => ['sometimes', 'string'],
        ]);

        $positions = Position::query()
            ->with('department')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when(
                $request->filled('department'),
                fn ($query) => $query->where('department_id', $this->resolveDepartmentId($request->string('department')->toString()) ?? -1)
            )
            ->orderBy('sort_order')
            ->orderBy('title')
            ->paginate($request->integer('per_page', 50));

        return PositionResource::collection($positions);
    }

    public function store(StorePositionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['department_id'] = $this->resolveDepartmentId($data['department_id'] ?? null);

        $position = Position::create($data);

        return (new PositionResource($position->load('department')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Position $position): PositionResource
    {
        return new PositionResource($position->load('department'));
    }

    public function update(UpdatePositionRequest $request, Position $position): PositionResource
    {
        $data = $request->validated();

        if (array_key_exists('department_id', $data)) {
            $data['department_id'] = $this->resolveDepartmentId($data['department_id']);
        }

        $position->update($data);

        return new PositionResource($position->load('department'));
    }

    public function destroy(Position $position): JsonResponse
    {
        $position->delete();

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
