<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'status' => AccountStatus::Active,
            'role_id' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Assign the Administrator role (Phase 5 — DEC-028; supersedes the
     * retired `is_admin` flag / former `admin()` state). The role row is
     * looked up-or-created idempotently, so this works regardless of
     * whether RolePermissionSeeder has run.
     */
    public function administrator(): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::query()->firstOrCreate(
                ['name' => Role::ADMINISTRATOR],
                ['label' => 'Administrator'],
            )->id,
        ]);
    }

    /**
     * Assign the Manager role (Phase 5). Manager intentionally carries no
     * permissions in V1 — see CLAUDE.md §10.
     */
    public function manager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::query()->firstOrCreate(
                ['name' => Role::MANAGER],
                ['label' => 'Manager'],
            )->id,
        ]);
    }

    /**
     * Assign the Staff role (Phase 5). Staff intentionally carries no
     * permissions in V1 — see CLAUDE.md §10.
     */
    public function staff(): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::query()->firstOrCreate(
                ['name' => Role::STAFF],
                ['label' => 'Staff'],
            )->id,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::Suspended,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::Inactive,
        ]);
    }
}
