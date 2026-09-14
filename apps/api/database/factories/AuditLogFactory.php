<?php

namespace Database\Factories;

use App\Enums\AuditSource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditActions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_user_id' => User::factory(),
            'action' => AuditActions::STAFF_UPDATED,
            'entity_type' => 'Staff',
            'entity_public_id' => fake()->uuid(),
            'changed_fields' => ['status'],
            'before' => ['status' => 'active'],
            'after' => ['status' => 'inactive'],
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'PHPUnit',
            'source' => AuditSource::Api,
        ];
    }
}
