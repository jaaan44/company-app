<?php

namespace App\Models;

use App\Enums\ContactStatus;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A person associated with a Client (Phase 8 — Clients & Contacts). Always
 * belongs to exactly one Client — no client-less contacts, no many-to-many
 * Contact↔Client relationship (docs/phases/V1_PHASE_08_DEFINITION.md).
 * Externally addressable via `public_id` (DEC-017).
 *
 * @property int $id
 * @property string $public_id
 * @property int $client_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $job_title
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 * @property ContactStatus $status
 * @property string|null $notes
 */
#[Fillable([
    'client_id', 'first_name', 'last_name', 'job_title', 'email', 'phone',
    'is_primary', 'status', 'notes',
])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ContactStatus::class,
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Contact $contact): void {
            $contact->public_id ??= (string) Str::ulid();

            // See Client::booted() — the DB-level defaults aren't
            // reflected on this in-memory instance after an insert unless
            // refreshed.
            $contact->status ??= ContactStatus::Active;
            $contact->is_primary ??= false;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
