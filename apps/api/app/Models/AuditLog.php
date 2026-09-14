<?php

namespace App\Models;

use App\Enums\AuditSource;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single general, project-wide audit entry (Phase 21, DEC-009/DEC-044)
 * — see the `audit_logs` migration for the full rationale. Append-only:
 * `UPDATED_AT = null` disables Eloquent's own update-timestamp
 * maintenance (there is no `updated_at` column at all), and no
 * application code anywhere calls `update()`/`delete()` on this model —
 * see AuditLogController, which exposes only `index`/`export`.
 *
 * @property int $id
 * @property int|null $actor_user_id
 * @property string $action
 * @property string $entity_type
 * @property string|null $entity_public_id
 * @property array<int, string>|null $changed_fields
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property AuditSource $source
 */
#[Fillable([
    'actor_user_id', 'action', 'entity_type', 'entity_public_id',
    'changed_fields', 'before', 'after', 'ip_address', 'user_agent', 'source',
])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * No `updated_at` column exists or is ever needed — see the class
     * docblock. `created_at` alone is still fully managed by Eloquent.
     */
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'before' => 'array',
            'after' => 'array',
            'source' => AuditSource::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * The acting User, if one could safely be resolved (see AuditLogger)
     * — null for e.g. a failed login against an unrecognized email.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
