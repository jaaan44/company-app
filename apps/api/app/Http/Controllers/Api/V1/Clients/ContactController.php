<?php

namespace App\Http\Controllers\Api\V1\Clients;

use App\Enums\ContactStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreContactRequest;
use App\Http\Requests\Clients\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Client;
use App\Models\Contact;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
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
 * docs/phases/V1_PHASE_08_DEFINITION.md). Create/update/delete are
 * audited (Phase 21, DEC-044) with curated status/client/primary
 * metadata only — personal contact details are not captured.
 */
class ContactController extends Controller
{
    /** @var array<int, string> */
    private const AUDITED_FIELDS = ['status', 'client_id', 'is_primary'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

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

        // The business create (including any primary-contact clearing)
        // and its required audit entry commit or roll back together
        // (Phase 21, DEC-044).
        $contact = DB::transaction(function () use ($request, $data): Contact {
            if ($this->makesPrimary($data)) {
                $this->clearOtherPrimaryContacts($data['client_id']);
            }

            $contact = Contact::create($data);
            $contact->load('client');

            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::CONTACT_CREATED,
                entityType: 'Contact',
                entityPublicId: $contact->public_id,
                after: $this->curatedSnapshot($contact),
            );

            return $contact;
        });

        return (new ContactResource($contact))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Contact $contact): ContactResource
    {
        return new ContactResource($contact->load('client'));
    }

    public function update(UpdateContactRequest $request, Contact $contact): ContactResource
    {
        $contact->load('client');
        $before = $this->curatedSnapshot($contact);

        $data = $request->validated();

        if (array_key_exists('client_id', $data)) {
            $data['client_id'] = $this->resolveClientId($data['client_id']);
        }

        DB::transaction(function () use ($request, $contact, $data, $before) {
            if ($this->makesPrimary($data)) {
                $this->clearOtherPrimaryContacts($data['client_id'] ?? $contact->client_id, $contact->id);
            }

            $contact->update($data);
            $contact->load('client');

            [$changedFields, $curatedBefore, $curatedAfter] = AuditLogger::diff(
                $before,
                $this->curatedSnapshot($contact),
                self::AUDITED_FIELDS,
            );

            if ($changedFields !== []) {
                $this->auditLogger->recordForRequest(
                    $request,
                    AuditActions::CONTACT_UPDATED,
                    entityType: 'Contact',
                    entityPublicId: $contact->public_id,
                    changedFields: $changedFields,
                    before: $curatedBefore,
                    after: $curatedAfter,
                );
            }
        });

        return new ContactResource($contact);
    }

    public function destroy(Request $request, Contact $contact): JsonResponse
    {
        $contact->load('client');
        $publicId = $contact->public_id;
        $before = $this->curatedSnapshot($contact);

        DB::transaction(function () use ($request, $contact, $publicId, $before) {
            $contact->delete();

            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::CONTACT_DELETED,
                entityType: 'Contact',
                entityPublicId: $publicId,
                before: $before,
            );
        });

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

    /**
     * @return array<string, mixed>
     */
    private function curatedSnapshot(Contact $contact): array
    {
        return [
            'status' => $contact->status->value,
            'client_id' => $contact->client?->public_id,
            'is_primary' => $contact->is_primary,
        ];
    }
}
