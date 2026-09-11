<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreDepartmentRequest;
use App\Http\Requests\Organization\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Departments (Phase 6 — Organization Structure). Read endpoints require
 * `organization.view`; writes require `organization.manage` — enforced by
 * route middleware (routes/api/v1.php), not here.
 */
class DepartmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', new Enum(OrganizationStatus::class)],
        ]);

        $departments = Department::query()
            ->withCount(['teams', 'positions'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return DepartmentResource::collection($departments);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::create($request->validated());

        return (new DepartmentResource($department))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Department $department): DepartmentResource
    {
        return new DepartmentResource($department->loadCount(['teams', 'positions']));
    }

    public function update(UpdateDepartmentRequest $request, Department $department): DepartmentResource
    {
        $department->update($request->validated());

        return new DepartmentResource($department->loadCount(['teams', 'positions']));
    }

    /**
     * A department that still has any team, position, or (Phase 7) staff
     * referencing it cannot be deleted — preventing an invalid/orphaned
     * organization structure (see docs/phases/V1_PHASE_06_DEFINITION.md).
     * The `restrictOnDelete()` foreign keys on `teams`/`positions`/`staff`
     * back this up at the database level; this check exists to return a
     * clear 409 instead of a raw database constraint error.
     */
    public function destroy(Department $department): JsonResponse
    {
        if ($department->teams()->exists() || $department->positions()->exists() || $department->staff()->exists()) {
            return response()->json([
                'message' => 'This department still has teams, positions, or staff assigned to it and cannot be deleted.',
            ], 409);
        }

        $department->delete();

        return response()->json(status: 204);
    }
}
