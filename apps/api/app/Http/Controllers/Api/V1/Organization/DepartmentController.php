<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreDepartmentRequest;
use App\Http\Requests\Organization\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

/**
 * Departments (Phase 6 — Organization Structure). Read endpoints require
 * `organization.view`; writes require `organization.manage` — enforced by
 * route middleware (routes/api/v1.php), not here. Create/update/delete
 * are audited (Phase 21, DEC-044) with curated name/status metadata only —
 * each business mutation and its required audit entry commit or roll back
 * together inside one `DB::transaction()`.
 */
class DepartmentController extends Controller
{
    /** @var array<int, string> */
    private const AUDITED_FIELDS = ['name', 'status'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

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
        $department = DB::transaction(function () use ($request) {
            $department = Department::create($request->validated());

            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::DEPARTMENT_CREATED,
                entityType: 'Department',
                entityPublicId: $department->public_id,
                after: $this->curatedSnapshot($department),
            );

            return $department;
        });

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
        $before = $this->curatedSnapshot($department);

        DB::transaction(function () use ($request, $department, $before) {
            $department->update($request->validated());

            [$changedFields, $curatedBefore, $curatedAfter] = AuditLogger::diff(
                $before,
                $this->curatedSnapshot($department),
                self::AUDITED_FIELDS,
            );

            if ($changedFields !== []) {
                $this->auditLogger->recordForRequest(
                    $request,
                    AuditActions::DEPARTMENT_UPDATED,
                    entityType: 'Department',
                    entityPublicId: $department->public_id,
                    changedFields: $changedFields,
                    before: $curatedBefore,
                    after: $curatedAfter,
                );
            }
        });

        return new DepartmentResource($department->loadCount(['teams', 'positions']));
    }

    /**
     * A department that still has any team, position, (Phase 7) staff, or
     * (Phase 14) announcement audience referencing it cannot be deleted —
     * preventing an invalid/orphaned organization structure (see
     * docs/phases/V1_PHASE_06_DEFINITION.md). The `restrictOnDelete()`
     * foreign keys on `teams`/`positions`/`staff`/`announcement_departments`
     * back this up at the database level; this check exists to return a
     * clear 409 instead of a raw database constraint error.
     */
    public function destroy(Request $request, Department $department): JsonResponse
    {
        if ($department->teams()->exists() || $department->positions()->exists() || $department->staff()->exists()) {
            return response()->json([
                'message' => 'This department still has teams, positions, or staff assigned to it and cannot be deleted.',
            ], 409);
        }

        if ($department->announcements()->exists()) {
            return response()->json([
                'message' => 'This department is still targeted by an announcement audience and cannot be deleted.',
            ], 409);
        }

        $publicId = $department->public_id;
        $before = $this->curatedSnapshot($department);

        DB::transaction(function () use ($request, $department, $publicId, $before) {
            $department->delete();

            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::DEPARTMENT_DELETED,
                entityType: 'Department',
                entityPublicId: $publicId,
                before: $before,
            );
        });

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function curatedSnapshot(Department $department): array
    {
        return [
            'name' => $department->name,
            'status' => $department->status->value,
        ];
    }
}
