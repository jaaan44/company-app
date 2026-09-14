<?php

namespace App\Http\Controllers\Api\V1\Clients;

use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Clients (Phase 8 — Clients & Contacts). Read endpoints require
 * `clients.view`; writes require `clients.manage` — enforced by route
 * middleware (routes/api/v1.php), not here. Create/update/delete are
 * audited (Phase 21, DEC-044) with curated status metadata only —
 * business-contact details are not captured in before/after.
 */
class ClientController extends Controller
{
    /** @var array<int, string> */
    private const AUDITED_FIELDS = ['status'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', new Enum(ClientStatus::class)],
            'q' => ['sometimes', 'string', 'max:255'],
        ]);

        $clients = Client::query()
            ->withCount('contacts')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->toString().'%';

                $query->where(function ($query) use ($term) {
                    $query->where('name', 'like', $term)
                        ->orWhere('client_code', 'like', $term);
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return ClientResource::collection($clients);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = Client::create($request->validated());

        $this->auditLogger->recordForRequest(
            $request,
            AuditActions::CLIENT_CREATED,
            entityType: 'Client',
            entityPublicId: $client->public_id,
            after: $this->curatedSnapshot($client),
        );

        return (new ClientResource($client->loadCount('contacts')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Client $client): ClientResource
    {
        return new ClientResource($client->loadCount('contacts'));
    }

    public function update(UpdateClientRequest $request, Client $client): ClientResource
    {
        $before = $this->curatedSnapshot($client);

        $client->update($request->validated());

        [$changedFields, $curatedBefore, $curatedAfter] = AuditLogger::diff(
            $before,
            $this->curatedSnapshot($client),
            self::AUDITED_FIELDS,
        );

        if ($changedFields !== []) {
            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::CLIENT_UPDATED,
                entityType: 'Client',
                entityPublicId: $client->public_id,
                changedFields: $changedFields,
                before: $curatedBefore,
                after: $curatedAfter,
            );
        }

        return new ClientResource($client->loadCount('contacts'));
    }

    /**
     * A client that still has any contact or project referencing it
     * cannot be deleted — preventing an orphaned record and preserving
     * business history (see docs/phases/V1_PHASE_08_DEFINITION.md and,
     * for projects, docs/phases/V1_PHASE_10_DEFINITION.md). The
     * `restrictOnDelete()` foreign keys on `contacts.client_id` and
     * `projects.client_id` back this up at the database level; these
     * checks exist to return a clear 409 instead of a raw database
     * constraint error.
     */
    public function destroy(Request $request, Client $client): JsonResponse
    {
        if ($client->contacts()->exists()) {
            return response()->json([
                'message' => 'This client still has contacts assigned to it and cannot be deleted.',
            ], 409);
        }

        if ($client->projects()->exists()) {
            return response()->json([
                'message' => 'This client still has projects assigned to it and cannot be deleted.',
            ], 409);
        }

        if ($client->serviceReports()->exists()) {
            return response()->json([
                'message' => 'This client still has service reports and cannot be deleted.',
            ], 409);
        }

        if ($client->incidentReports()->exists()) {
            return response()->json([
                'message' => 'This client still has incident reports and cannot be deleted.',
            ], 409);
        }

        $publicId = $client->public_id;
        $before = $this->curatedSnapshot($client);

        $client->delete();

        $this->auditLogger->recordForRequest(
            $request,
            AuditActions::CLIENT_DELETED,
            entityType: 'Client',
            entityPublicId: $publicId,
            before: $before,
        );

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function curatedSnapshot(Client $client): array
    {
        return [
            'status' => $client->status->value,
        ];
    }
}
