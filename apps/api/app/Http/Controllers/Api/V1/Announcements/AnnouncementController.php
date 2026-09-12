<?php

namespace App\Http\Controllers\Api\V1\Announcements;

use App\Enums\AnnouncementAudienceType;
use App\Enums\AnnouncementStatus;
use App\Http\Controllers\Api\V1\Announcements\Concerns\NotifiesAnnouncementAudience;
use App\Http\Controllers\Controller;
use App\Http\Requests\Announcements\StoreAnnouncementRequest;
use App\Http\Requests\Announcements\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

/**
 * The management surface for Announcements (Phase 14) — self-service
 * employee visibility lives entirely in MyAnnouncementController instead.
 * The entire surface (reads and writes alike) requires
 * `announcements.manage` (Administrator-only, via the centralized
 * Gate::before override) — enforced by route middleware
 * (routes/api/v1.php), not here. Unlike every prior module, there is no
 * companion `announcements.view` for Manager/Staff: ordinary employee
 * visibility is served by /me/announcements instead (see docs/phases/
 * V1_PHASE_14_DEFINITION.md's Permissions section).
 */
class AnnouncementController extends Controller
{
    use NotifiesAnnouncementAudience;

    private const WITH_RELATIONS = ['departments', 'teams', 'creator.staff', 'publisher.staff'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', new Enum(AnnouncementStatus::class)],
            'audience_type' => ['sometimes', new Enum(AnnouncementAudienceType::class)],
        ]);

        $announcements = Announcement::query()
            ->with(self::WITH_RELATIONS)
            ->withCount('acknowledgements')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('audience_type'), fn ($query) => $query->where('audience_type', $request->string('audience_type')))
            ->orderByDesc('published_at')
            ->orderByDesc('updated_at')
            ->paginate($request->integer('per_page', 50));

        return AnnouncementResource::collection($announcements);
    }

    public function show(Announcement $announcement): AnnouncementResource
    {
        return new AnnouncementResource($announcement->load(self::WITH_RELATIONS)->loadCount('acknowledgements'));
    }

    /**
     * Always creates a 'draft' — publishing is a separate, explicit action
     * (see publish()). The audience is fully specified in this same
     * request (see StoreAnnouncementRequest/ValidatesAnnouncementAudience).
     */
    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $data = $request->validated();

        $announcement = DB::transaction(function () use ($data, $request) {
            $announcement = Announcement::create([
                'title' => $data['title'],
                'body' => $data['body'],
                'audience_type' => $data['audience_type'] ?? AnnouncementAudienceType::CompanyWide,
                'created_by_user_id' => $request->user()->id,
            ]);

            $this->syncAudience($announcement, $data);

            return $announcement;
        });

        return (new AnnouncementResource($announcement->load(self::WITH_RELATIONS)->loadCount('acknowledgements')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Only a still-draft Announcement may be edited. Once published, an
     * Announcement's title/body/audience become immutable content — an
     * acknowledgement permanently corresponds to the exact configuration
     * that existed at publication time, and nothing may silently rewrite
     * it underneath an existing acknowledgement (see docs/phases/
     * V1_PHASE_14_DEFINITION.md's Editing section). Correcting a mistake
     * after publication means archive() + a new corrected draft, never an
     * edit — no revision history, content-versioning, or acknowledgement
     * migration/invalidation exists for this. audience_type/department_ids/
     * team_ids are only touched atomically, together (see
     * ValidatesAnnouncementAudience) — a request omitting all three leaves
     * the existing audience untouched.
     */
    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        if ($announcement->status !== AnnouncementStatus::Draft) {
            abort(409, 'Only a draft announcement may be edited. Archive this announcement and create a corrected draft instead.');
        }

        $data = $request->validated();

        DB::transaction(function () use ($data, $announcement) {
            $announcement->update(array_intersect_key($data, array_flip(['title', 'body'])));

            if (array_key_exists('audience_type', $data)) {
                $announcement->update(['audience_type' => $data['audience_type']]);
                $this->syncAudience($announcement, $data);
            }
        });

        return new AnnouncementResource($announcement->load(self::WITH_RELATIONS)->loadCount('acknowledgements'));
    }

    /**
     * Only a still-draft Announcement may be hard-deleted — nothing has
     * been broadcast yet, so there is no history to preserve. A
     * published/archived Announcement is retired via archive() instead
     * (see docs/phases/V1_PHASE_14_DEFINITION.md's Deletion vs. Archival).
     */
    public function destroy(Announcement $announcement): JsonResponse
    {
        if ($announcement->status !== AnnouncementStatus::Draft) {
            abort(409, 'Only a draft announcement may be deleted; archive a published announcement instead.');
        }

        $announcement->delete();

        return response()->json(status: 204);
    }

    /**
     * Only valid from 'draft'. Sets published_at/published_by_user_id
     * server-side — never client-supplied, never re-set by a later edit.
     * Also fans out a Notification to the resolved audience (Phase 15,
     * DEC-038) — a publish-time snapshot, inside the same transaction as
     * the status change itself, so the two either both succeed or both
     * roll back. Because this action only ever succeeds once per
     * Announcement (the guard below), the fan-out cannot double-fire.
     */
    public function publish(Request $request, Announcement $announcement): AnnouncementResource
    {
        if ($announcement->status !== AnnouncementStatus::Draft) {
            abort(409, 'Only a draft announcement may be published.');
        }

        DB::transaction(function () use ($request, $announcement) {
            $announcement->update([
                'status' => AnnouncementStatus::Published,
                'published_at' => now(),
                'published_by_user_id' => $request->user()->id,
            ]);

            $this->notifyAudience($announcement);
        });

        return new AnnouncementResource($announcement->load(self::WITH_RELATIONS)->loadCount('acknowledgements'));
    }

    /**
     * Only valid from 'published'. Terminal — no un-archive/republish
     * endpoint exists; correcting a mistakenly-archived announcement means
     * creating a new one.
     */
    public function archive(Announcement $announcement): AnnouncementResource
    {
        if ($announcement->status !== AnnouncementStatus::Published) {
            abort(409, 'Only a published announcement may be archived.');
        }

        $announcement->update(['status' => AnnouncementStatus::Archived]);

        return new AnnouncementResource($announcement->load(self::WITH_RELATIONS)->loadCount('acknowledgements'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncAudience(Announcement $announcement, array $data): void
    {
        $announcement->departments()->sync($this->resolveIds(Department::class, $data['department_ids'] ?? []));
        $announcement->teams()->sync($this->resolveIds(Team::class, $data['team_ids'] ?? []));
    }

    /**
     * @param  class-string<Department|Team>  $modelClass
     * @param  array<int, string>  $publicIds
     * @return array<int, int>
     */
    private function resolveIds(string $modelClass, array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }

        return $modelClass::query()->whereIn('public_id', $publicIds)->pluck('id')->all();
    }
}
