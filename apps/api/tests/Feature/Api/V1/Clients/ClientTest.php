<?php

namespace Tests\Feature\Api\V1\Clients;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Contact;
use App\Models\IncidentReport;
use App\Models\Project;
use App\Models\ServiceReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    // --- CRUD ---------------------------------------------------------

    public function test_administrator_can_list_clients(): void
    {
        $this->actingAsAdministrator();
        Client::factory()->count(2)->create();

        $this->getJson('/api/v1/clients')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_administrator_can_create_a_client(): void
    {
        $this->actingAsAdministrator();

        $response = $this->postJson('/api/v1/clients', [
            'client_code' => 'CL-0001',
            'name' => 'Acme Corporation',
            'email' => 'hello@acme.test',
            'website' => 'https://acme.test',
        ]);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'client_code' => 'CL-0001',
                    'name' => 'Acme Corporation',
                    'status' => 'active',
                    'email' => 'hello@acme.test',
                    'contacts_count' => 0,
                ],
            ])
            ->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('clients', ['name' => 'Acme Corporation']);
    }

    public function test_creating_a_client_requires_a_name(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/clients', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_client_code_must_be_unique_when_present(): void
    {
        $this->actingAsAdministrator();
        Client::factory()->create(['client_code' => 'CL-0001']);

        $this->postJson('/api/v1/clients', [
            'name' => 'Another Client',
            'client_code' => 'CL-0001',
        ])->assertUnprocessable()->assertJsonValidationErrors('client_code');
    }

    public function test_a_client_can_be_created_without_a_client_code(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/clients', ['name' => 'No Code Inc.'])
            ->assertCreated()
            ->assertJson(['data' => ['client_code' => null]]);
    }

    public function test_administrator_can_view_a_client_by_public_id(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();

        $this->getJson("/api/v1/clients/{$client->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['public_id' => $client->public_id]]);
    }

    public function test_viewing_a_client_by_internal_numeric_id_fails(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();

        $this->getJson("/api/v1/clients/{$client->id}")->assertNotFound();
    }

    public function test_administrator_can_update_a_client(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create(['name' => 'Old Name']);

        $this->putJson("/api/v1/clients/{$client->public_id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJson(['data' => ['name' => 'New Name']]);
    }

    public function test_administrator_can_change_a_clients_status(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();

        $this->putJson("/api/v1/clients/{$client->public_id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJson(['data' => ['status' => 'inactive']]);

        $this->assertSame(ClientStatus::Inactive, $client->fresh()->status);
    }

    public function test_updating_a_client_to_a_duplicate_client_code_is_rejected(): void
    {
        $this->actingAsAdministrator();
        Client::factory()->create(['client_code' => 'CL-TAKEN']);
        $client = Client::factory()->create(['client_code' => 'CL-MINE']);

        $this->putJson("/api/v1/clients/{$client->public_id}", ['client_code' => 'CL-TAKEN'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_code');
    }

    public function test_updating_a_client_without_changing_its_client_code_is_allowed(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create(['client_code' => 'CL-UNCHANGED']);

        $this->putJson("/api/v1/clients/{$client->public_id}", ['client_code' => 'CL-UNCHANGED', 'name' => 'Renamed'])
            ->assertOk();
    }

    public function test_administrator_can_delete_a_client_with_no_contacts(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();

        $this->deleteJson("/api/v1/clients/{$client->public_id}")->assertNoContent();

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_deleting_a_client_with_contacts_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        Contact::factory()->create(['client_id' => $client->id]);

        $this->deleteJson("/api/v1/clients/{$client->public_id}")->assertStatus(409);

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_deleting_a_client_with_projects_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        Project::factory()->create(['client_id' => $client->id]);

        $this->deleteJson("/api/v1/clients/{$client->public_id}")->assertStatus(409);

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_deleting_a_client_with_service_reports_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        ServiceReport::factory()->create(['client_id' => $client->id]);

        $this->deleteJson("/api/v1/clients/{$client->public_id}")->assertStatus(409);

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_deleting_a_client_with_incident_reports_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        IncidentReport::factory()->create(['client_id' => $client->id]);

        $this->deleteJson("/api/v1/clients/{$client->public_id}")->assertStatus(409);

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    // --- Filters / search ------------------------------------------------

    public function test_clients_can_be_filtered_by_status(): void
    {
        $this->actingAsAdministrator();
        Client::factory()->create(['name' => 'Active One']);
        Client::factory()->inactive()->create(['name' => 'Inactive One']);

        $this->getJson('/api/v1/clients?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['name' => 'Inactive One']]]);
    }

    public function test_clients_can_be_searched_by_name_or_client_code(): void
    {
        $this->actingAsAdministrator();
        Client::factory()->create(['name' => 'Acme Corporation', 'client_code' => 'CL-1001']);
        Client::factory()->create(['name' => 'Globex Corporation', 'client_code' => 'CL-1002']);

        $this->getJson('/api/v1/clients?q=Acme')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['name' => 'Acme Corporation']]]);

        $this->getJson('/api/v1/clients?q=CL-1002')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['client_code' => 'CL-1002']]]);
    }

    public function test_clients_report_their_contact_count(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        Contact::factory()->count(3)->create(['client_id' => $client->id]);

        $this->getJson("/api/v1/clients/{$client->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['contacts_count' => 3]]);
    }
}
