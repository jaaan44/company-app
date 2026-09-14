<?php

namespace App\Http\Controllers\Api\V1\Organization;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StorePositionRequest;
use App\Http\Requests\Organization\UpdatePositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\Department;
use App\Models\Position;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Positions (Phase 6 — Organization Structure). Standalone organizational
 * master data — not yet associated with any staff record (Phase 7). Read
 * endpoints require `organization.view`; writes require
 * `organization.manage` — enforced by route middleware
 * (routes/api/v1.php), not here. Create/update/delete are audited (Phase
 * 21, DEC-044) with curated title/status/department metadata only.
 */
class PositionController extends Controller
{
    /** @var array<int, string> */
    private const AUDITED_FIELDS = ['title', 'status', 'department_id'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

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
        $position->load('department');

        $this->auditLogger->recordForRequest(
            $request,
            AuditActions::POSITION_CREATED,
            entityType: 'Position',
            entityPublicId: $position->public_id,
            after: $this->curatedSnapshot($position),
        );

        return (new PositionResource($position))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Position $position): PositionResource
    {
        return new PositionResource($position->load('department'));
    }

    public function update(UpdatePositionRequest $request, Position $position): PositionResource
    {
        $position->load('department');
        $before = $this->curatedSnapshot($position);

        $data = $request->validated();

        if (array_key_exists('department_id', $data)) {
            $data['department_id'] = $this->resolveDepartmentId($data['department_id']);
        }

        $position->update($data);
        $position->load('department');

        [$changedFields, $curatedBefore, $curatedAfter] = AuditLogger::diff(
            $before,
            $this->curatedSnapshot($position),
            self::AUDITED_FIELDS,
        );

        if ($changedFields !== []) {
            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::POSITION_UPDATED,
                entityType: 'Position',
                entityPublicId: $position->public_id,
                changedFields: $changedFields,
                before: $curatedBefore,
                after: $curatedAfter,
            );
        }

        return new PositionResource($position);
    }

    /**
     * A position that still has any (Phase 7) staff assigned to it cannot
     * be deleted — same relational-integrity protection as
     * DepartmentController::destroy, backed by the `staff.position_id`
     * `restrictOnDelete()` foreign key.
     */
    public function destroy(Request $request, Position $position): JsonResponse
    {
        if ($position->staff()->exists()) {
            return response()->json([
                'message' => 'This position still has staff assigned to it and cannot be deleted.',
            ], 409);
        }

        $position->load('department');
        $publicId = $position->public_id;
        $before = $this->curatedSnapshot($position);

        $position->delete();

        $this->auditLogger->recordForRequest(
            $request,
            AuditActions::POSITION_DELETED,
            entityType: 'Position',
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
    private function curatedSnapshot(Position $position): array
    {
        return [
            'title' => $position->title,
            'status' => $position->status->value,
            'department_id' => $position->department?->public_id,
        ];
    }
}
