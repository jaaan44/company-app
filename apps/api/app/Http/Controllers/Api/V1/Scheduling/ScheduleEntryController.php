<?php

namespace App\Http\Controllers\Api\V1\Scheduling;

use App\Enums\ScheduleEntryActivityType;
use App\Http\Controllers\Api\V1\Scheduling\Concerns\AuthorizesScheduleEntryAccess;
use App\Http\Controllers\Api\V1\StaffOperations\Concerns\RequiresLinkedStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\StoreScheduleEntryRequest;
use App\Http\Requests\Scheduling\UpdateScheduleEntryRequest;
use App\Http\Resources\ScheduleEntryResource;
use App\Models\Project;
use App\Models\ScheduleEntry;
use App\Models\Staff;
use App\Support\Scheduling\ScheduleEntryTiming;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Manually created Schedule Entries (Phase 17 — Scheduler) — the
 * Scheduler-owned entity for activities with no other system-of-record
 * module (a meeting, client visit, service appointment, company event,
 * training session, or other internal activity). A flat, top-level
 * resource, mirroring Tasks: neither reads nor writes are gated by a
 * bare `can:<permission>` route middleware — visibility and management
 * authority are both resolved in-controller
 * (AuthorizesScheduleEntryAccess). See docs/phases/
 * V1_PHASE_17_DEFINITION.md.
 */
class ScheduleEntryController extends Controller
{
    use AuthorizesScheduleEntryAccess;
    use RequiresLinkedStaff;

    private const WITH_RELATIONS = ['creator', 'project', 'participants'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'project' => ['sometimes', 'string'],
            'activity_type' => ['sometimes', new Enum(ScheduleEntryActivityType::class)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = $this->scopeVisibleScheduleEntries($request, ScheduleEntry::query()->with(self::WITH_RELATIONS));

        $entries = $query
            ->when(
                $request->filled('project'),
                fn ($query) => $query->where('project_id', $this->resolveId(Project::class, $request->string('project')->toString()) ?? -1)
            )
            ->when($request->filled('activity_type'), fn ($query) => $query->where('activity_type', $request->string('activity_type')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('ends_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('starts_at', '<=', $request->date('to')))
            ->orderBy('starts_at')
            ->paginate($request->integer('per_page', 50));

        return ScheduleEntryResource::collection($entries);
    }

    public function store(StoreScheduleEntryRequest $request): JsonResponse
    {
        $staff = $this->resolveAuthenticatedStaff($request);
        $data = $request->validated();

        $timing = ScheduleEntryTiming::resolve($data['starts_at'], $data['ends_at'], $data['is_all_day'] ?? false);

        $entry = ScheduleEntry::create([
            'creator_staff_id' => $staff->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'activity_type' => $data['activity_type'],
            'starts_at' => $timing['starts_at'],
            'ends_at' => $timing['ends_at'],
            'is_all_day' => $data['is_all_day'] ?? false,
            'project_id' => array_key_exists('project_id', $data) ? $this->resolveId(Project::class, $data['project_id']) : null,
        ]);

        if (array_key_exists('participant_staff_ids', $data)) {
            $entry->participants()->sync($this->resolveParticipantIds($data['participant_staff_ids'], $staff->id));
        }

        return (new ScheduleEntryResource($entry->load(self::WITH_RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, ScheduleEntry $scheduleEntry): ScheduleEntryResource
    {
        $this->authorizeView($request, $scheduleEntry);

        return new ScheduleEntryResource($scheduleEntry->load(self::WITH_RELATIONS));
    }

    /**
     * Full field access for the creator, a Project Lead of the entry's
     * linked Project, or Administrator (AuthorizesScheduleEntryAccess);
     * a mere participant may view (authorizeManage() calls
     * authorizeView() first) but never update.
     */
    public function update(UpdateScheduleEntryRequest $request, ScheduleEntry $scheduleEntry): ScheduleEntryResource
    {
        if (! $this->authorizeManage($request, $scheduleEntry)) {
            abort(403, 'You do not have permission to update this schedule entry.');
        }

        $data = $request->validated();

        $isAllDay = array_key_exists('is_all_day', $data) ? $data['is_all_day'] : $scheduleEntry->is_all_day;

        if (array_key_exists('starts_at', $data) || array_key_exists('ends_at', $data) || array_key_exists('is_all_day', $data)) {
            $startsInput = $data['starts_at'] ?? ($isAllDay ? $scheduleEntry->starts_at->toDateString() : $scheduleEntry->starts_at->toIso8601String());
            $endsInput = $data['ends_at'] ?? ($isAllDay ? $scheduleEntry->ends_at->toDateString() : $scheduleEntry->ends_at->toIso8601String());

            $timing = ScheduleEntryTiming::resolve($startsInput, $endsInput, $isAllDay);

            $data['starts_at'] = $timing['starts_at'];
            $data['ends_at'] = $timing['ends_at'];
            $data['is_all_day'] = $isAllDay;
        }

        $participantIds = null;

        if (array_key_exists('participant_staff_ids', $data)) {
            $participantIds = $this->resolveParticipantIds($data['participant_staff_ids'], $scheduleEntry->creator_staff_id);
            unset($data['participant_staff_ids']);
        }

        $scheduleEntry->update($data);

        if ($participantIds !== null) {
            $scheduleEntry->participants()->sync($participantIds);
        }

        return new ScheduleEntryResource($scheduleEntry->load(self::WITH_RELATIONS));
    }

    public function destroy(Request $request, ScheduleEntry $scheduleEntry): JsonResponse
    {
        if (! $this->authorizeManage($request, $scheduleEntry)) {
            abort(403, 'You do not have permission to delete this schedule entry.');
        }

        $scheduleEntry->delete();

        return response()->json(status: 204);
    }

    /**
     * The creator is never duplicated into the participant pivot
     * (docs/phases/V1_PHASE_17_DEFINITION.md's Participants design) —
     * their own id is filtered out here even if a client supplies it.
     *
     * @param  array<int, string>  $publicIds
     * @return array<int, int>
     */
    private function resolveParticipantIds(array $publicIds, ?int $excludeStaffId): array
    {
        return Staff::query()
            ->whereIn('public_id', $publicIds)
            ->when($excludeStaffId !== null, fn ($query) => $query->whereKeyNot($excludeStaffId))
            ->pluck('id')
            ->all();
    }

    /**
     * @param  class-string<Project>  $modelClass
     */
    private function resolveId(string $modelClass, ?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return $modelClass::query()->where('public_id', $publicId)->value('id');
    }
}
