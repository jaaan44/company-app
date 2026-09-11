<?php

namespace App\Http\Controllers\Api\V1\Clients;

use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;

/**
 * Clients (Phase 8 — Clients & Contacts). Read endpoints require
 * `clients.view`; writes require `clients.manage` — enforced by route
 * middleware (routes/api/v1.php), not here.
 */
class ClientController extends Controller
{
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
        $client->update($request->validated());

        return new ClientResource($client->loadCount('contacts'));
    }

    /**
     * A client that still has any contact referencing it cannot be
     * deleted — preventing an orphaned contact record and preserving
     * business history (see docs/phases/V1_PHASE_08_DEFINITION.md). The
     * `restrictOnDelete()` foreign key on `contacts.client_id` backs this
     * up at the database level; this check exists to return a clear 409
     * instead of a raw database constraint error.
     */
    public function destroy(Client $client): JsonResponse
    {
        if ($client->contacts()->exists()) {
            return response()->json([
                'message' => 'This client still has contacts assigned to it and cannot be deleted.',
            ], 409);
        }

        $client->delete();

        return response()->json(status: 204);
    }
}
