<?php

namespace App\Models;

use App\Enums\ClientStatus;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A company, organization, business, or customer entity Company App's own
 * company does business with (Phase 8 — Clients & Contacts). The reusable
 * customer-data foundation later modules (Projects, Tasks, Work Logs,
 * Messaging, reporting) reference. Externally addressable via `public_id`
 * (DEC-017); the internal numeric id is never exposed.
 *
 * @property int $id
 * @property string $public_id
 * @property string|null $client_code
 * @property string $name
 * @property ClientStatus $status
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state_province
 * @property string|null $postal_code
 * @property string|null $country
 * @property string|null $notes
 */
#[Fillable([
    'client_code', 'name', 'status', 'email', 'phone', 'website',
    'address_line1', 'address_line2', 'city', 'state_province', 'postal_code', 'country',
    'notes',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Client $client): void {
            $client->public_id ??= (string) Str::ulid();

            // The migration's DB-level default isn't reflected on this
            // in-memory instance after an insert unless refreshed — same
            // pattern as Department/Team/Position/Staff.
            $client->status ??= ClientStatus::Active;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Projects associated with this Client (Phase 10). Optional — a
     * Project may be internal (client_id = null) and never appears here.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
