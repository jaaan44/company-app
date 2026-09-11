<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\StaffStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\Department;
use App\Models\Position;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Staff — the company personnel directory and employment-profile
 * foundation (Phase 7). Read endpoints require `staff.view`; writes
 * require `staff.manage` — enforced by route middleware
 * (routes/api/v1.php), not here.
 */
class StaffController extends Controller
{
    private const WITH_RELATIONS = ['department', 'team', 'position', 'manager', 'user'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', new Enum(StaffStatus::class)],
            'department' => ['sometimes', 'string'],
            'team' => ['sometimes', 'string'],
            'position' => ['sometimes', 'string'],
            'manager' => ['sometimes', 'string'],
            'q' => ['sometimes', 'string', 'max:255'],
        ]);

        $staff = Staff::query()
            ->with(self::WITH_RELATIONS)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when(
                $request->filled('department'),
                fn ($query) => $query->where('department_id', $this->resolveId(Department::class, $request->string('department')->toString()) ?? -1)
            )
            ->when(
                $request->filled('team'),
                fn ($query) => $query->where('team_id', $this->resolveId(Team::class, $request->string('team')->toString()) ?? -1)
            )
            ->when(
                $request->filled('position'),
                fn ($query) => $query->where('position_id', $this->resolveId(Position::class, $request->string('position')->toString()) ?? -1)
            )
            ->when(
                $request->filled('manager'),
                fn ($query) => $query->where('manager_id', $this->resolveId(Staff::class, $request->string('manager')->toString()) ?? -1)
            )
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->toString().'%';

                $query->where(function ($query) use ($term) {
                    $query->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('preferred_name', 'like', $term)
                        ->orWhere('employee_number', 'like', $term);
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($request->integer('per_page', 50));

        return StaffResource::collection($staff);
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $data = $this->resolveReferences($request->validated(), $request);

        $staffMember = Staff::create($data);

        return (new StaffResource($staffMember->load(self::WITH_RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Staff $staff): StaffResource
    {
        return new StaffResource($staff->load(self::WITH_RELATIONS));
    }

    public function update(UpdateStaffRequest $request, Staff $staff): StaffResource
    {
        $data = $this->resolveReferences($request->validated(), $request);

        $staff->update($data);

        return new StaffResource($staff->load(self::WITH_RELATIONS));
    }

    /**
     * A staff member who still has direct reports cannot be deleted until
     * they're reassigned — preventing an orphaned/invalid reporting
     * structure, the same relational-integrity philosophy as
     * DepartmentController::destroy.
     */
    public function destroy(Staff $staff): JsonResponse
    {
        if ($staff->directReports()->exists()) {
            return response()->json([
                'message' => 'This staff member still has direct reports and cannot be deleted.',
            ], 409);
        }

        $staff->delete();

        return response()->json(status: 204);
    }

    /**
     * Resolves every client-supplied public_id reference (department_id,
     * team_id, position_id, manager_id, user_id) present in $data to its
     * internal numeric id, and — when department_id wasn't explicitly
     * supplied but a team was — derives it from the team's own department
     * (docs/phases/V1_PHASE_07_DEFINITION.md).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveReferences(array $data, Request $request): array
    {
        foreach ([
            'department_id' => Department::class,
            'team_id' => Team::class,
            'position_id' => Position::class,
            'manager_id' => Staff::class,
            'user_id' => User::class,
        ] as $field => $modelClass) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->resolveId($modelClass, $data[$field]);
            }
        }

        if (! $request->filled('department_id') && ! empty($data['team_id'])) {
            $data['department_id'] = Team::query()->find($data['team_id'])?->department_id;
        }

        return $data;
    }

    /**
     * @param  class-string<Department|Position|Staff|Team|User>  $modelClass
     */
    private function resolveId(string $modelClass, ?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return $modelClass::query()->where('public_id', $publicId)->value('id');
    }
}
