<?php

namespace App\Http\Controllers\Api\V1\Clients;

use App\Enums\ContactStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreContactRequest;
use App\Http\Requests\Clients\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Client;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

/**
 * Contacts (Phase 8 — Clients & Contacts). Read endpoints require
 * `clients.view`; writes require `clients.manage` — enforced by route
 * middleware (routes/api/v1.php), not here. Contacts share Client's
 * permissions; there is no separate `contacts.*` permission pair (see
 * docs/phases/V1_PHASE_08_DEFINITION.md).
 */
class ContactController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'client' => ['sometimes', 'string'],
            'status' => ['sometimes', new Enum(ContactStatus::class)],
            'is_primary' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:255'],
        ]);

        $contacts = Contact::query()
            ->with('client')
            ->when(
                $request->filled('client'),
                fn ($query) => $query->where('client_id', $this->resolveClientId($request->string('client')->toString()) ?? -1)
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->has('is_primary'), fn ($query) => $query->where('is_primary', $request->boolean('is_primary')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->toString().'%';

                $query->where(function ($query) use ($term) {
                    $query->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($request->integer('per_page', 50));

        return ContactResource::collection($contacts);
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['client_id'] = $this->resolveClientId($data['client_id']);

        $contact = $this->makesPrimary($data)
            ? DB::transaction(function () use ($data): Contact {
                $this->clearOtherPrimaryContacts($data['client_id']);

                return Contact::create($data);
            })
            : Contact::create($data);

        return (new ContactResource($contact->load('client')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Contact $contact): ContactResource
    {
        return new ContactResource($contact->load('client'));
    }

    public function update(UpdateContactRequest $request, Contact $contact): ContactResource
    {
        $data = $request->validated();

        if (array_key_exists('client_id', $data)) {
            $data['client_id'] = $this->resolveClientId($data['client_id']);
        }

        if ($this->makesPrimary($data)) {
            DB::transaction(function () use ($data, $contact): void {
                $this->clearOtherPrimaryContacts($data['client_id'] ?? $contact->client_id, $contact->id);
                $contact->update($data);
            });
        } else {
            $contact->update($data);
        }

        return new ContactResource($contact->load('client'));
    }

    public function destroy(Contact $contact): JsonResponse
    {
        $contact->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function makesPrimary(array $data): bool
    {
        return ($data['is_primary'] ?? false) === true;
    }

    /**
     * At most one primary contact may exist per client
     * (docs/phases/V1_PHASE_08_DEFINITION.md) — clears any other primary
     * contact for the given client before the caller saves this one,
     * inside the same transaction.
     */
    private function clearOtherPrimaryContacts(int $clientId, ?int $exceptContactId = null): void
    {
        Contact::query()
            ->where('client_id', $clientId)
            ->where('is_primary', true)
            ->when($exceptContactId !== null, fn ($query) => $query->where('id', '!=', $exceptContactId))
            ->update(['is_primary' => false]);
    }

    private function resolveClientId(?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return Client::query()->where('public_id', $publicId)->value('id');
    }
}
