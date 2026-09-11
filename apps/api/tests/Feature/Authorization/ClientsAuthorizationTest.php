<?php

namespace Tests\Feature\Authorization;

use App\Enums\AccountStatus;
use App\Models\Client;
use App\Models\Contact;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-level authorization coverage for the Phase 8 Clients & Contacts
 * endpoints (CLAUDE.md §18): 'clients.view' gates reads, 'clients.manage'
 * gates writes for both resources — enforced by route middleware, not
 * merely by the controller. Mirrors StaffAuthorizationTest's pattern.
 */
class ClientsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Manager/Staff only hold 'clients.view' because the seeder
        // grants it — exercise the real catalog, not a hand-attached
        // stand-in.
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_administrator_can_view_and_manage_clients_and_contacts(): void
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/clients')->assertOk();
        $this->getJson('/api/v1/contacts')->assertOk();

        $response = $this->postJson('/api/v1/clients', ['name' => 'Acme Corporation']);
        $response->assertCreated();

        $this->postJson('/api/v1/contacts', [
            'client_id' => $response->json('data.public_id'),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ])->assertCreated();
    }

    public function test_manager_can_view_but_not_manage_clients_and_contacts(): void
    {
        $user = User::factory()->manager()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/clients')->assertOk();
        $this->getJson('/api/v1/contacts')->assertOk();

        $this->postJson('/api/v1/clients', ['name' => 'Acme Corporation'])->assertForbidden();

        $client = Client::factory()->create();
        $this->postJson('/api/v1/contacts', [
            'client_id' => $client->public_id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ])->assertForbidden();
    }

    public function test_staff_can_view_but_not_manage_clients_and_contacts(): void
    {
        $user = User::factory()->staff()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/clients')->assertOk();
        $this->getJson('/api/v1/contacts')->assertOk();

        $client = Client::factory()->create();
        $contact = Contact::factory()->create(['client_id' => $client->id]);

        $this->putJson("/api/v1/clients/{$client->public_id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/clients/{$client->public_id}")->assertForbidden();
        $this->putJson("/api/v1/contacts/{$contact->public_id}", ['first_name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/contacts/{$contact->public_id}")->assertForbidden();
    }

    public function test_user_with_no_role_cannot_view_or_manage_clients_or_contacts(): void
    {
        $user = User::factory()->create(['role_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/clients')->assertForbidden();
        $this->getJson('/api/v1/contacts')->assertForbidden();
        $this->postJson('/api/v1/clients', ['name' => 'Acme Corporation'])->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/clients')->assertUnauthorized();
        $this->getJson('/api/v1/contacts')->assertUnauthorized();
        $this->postJson('/api/v1/clients', ['name' => 'Acme Corporation'])->assertUnauthorized();
    }

    public function test_suspended_account_loses_clients_access_mid_session(): void
    {
        $user = User::factory()->administrator()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $user->status = AccountStatus::Suspended;
        $user->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/clients')
            ->assertForbidden();
    }
}
