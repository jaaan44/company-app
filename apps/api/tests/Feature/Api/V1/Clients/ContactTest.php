<?php

namespace Tests\Feature\Api\V1\Clients;

use App\Enums\ContactStatus;
use App\Models\Client;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    // --- CRUD ---------------------------------------------------------

    public function test_administrator_can_list_contacts(): void
    {
        $this->actingAsAdministrator();
        Contact::factory()->count(2)->create();

        $this->getJson('/api/v1/contacts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_administrator_can_create_a_contact(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();

        $response = $this->postJson('/api/v1/contacts', [
            'client_id' => $client->public_id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'job_title' => 'Procurement Manager',
            'email' => 'jane.doe@acme.test',
        ]);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'full_name' => 'Jane Doe',
                    'job_title' => 'Procurement Manager',
                    'status' => 'active',
                    'is_primary' => false,
                    'client' => ['public_id' => $client->public_id],
                ],
            ])
            ->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('contacts', ['first_name' => 'Jane', 'client_id' => $client->id]);
    }

    public function test_creating_a_contact_requires_a_client_first_name_and_last_name(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/contacts', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id', 'first_name', 'last_name']);
    }

    public function test_an_unknown_client_public_id_is_rejected(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/contacts', [
            'client_id' => 'not-a-real-ulid',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ])->assertUnprocessable()->assertJsonValidationErrors('client_id');
    }

    public function test_administrator_can_view_a_contact_by_public_id(): void
    {
        $this->actingAsAdministrator();
        $contact = Contact::factory()->create();

        $this->getJson("/api/v1/contacts/{$contact->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['public_id' => $contact->public_id]]);
    }

    public function test_viewing_a_contact_by_internal_numeric_id_fails(): void
    {
        $this->actingAsAdministrator();
        $contact = Contact::factory()->create();

        $this->getJson("/api/v1/contacts/{$contact->id}")->assertNotFound();
    }

    public function test_administrator_can_update_a_contact(): void
    {
        $this->actingAsAdministrator();
        $contact = Contact::factory()->create(['first_name' => 'Old']);

        $this->putJson("/api/v1/contacts/{$contact->public_id}", ['first_name' => 'New'])
            ->assertOk()
            ->assertJson(['data' => ['first_name' => 'New']]);
    }

    public function test_administrator_can_change_a_contacts_status(): void
    {
        $this->actingAsAdministrator();
        $contact = Contact::factory()->create();

        $this->putJson("/api/v1/contacts/{$contact->public_id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJson(['data' => ['status' => 'inactive']]);

        $this->assertSame(ContactStatus::Inactive, $contact->fresh()->status);
    }

    public function test_administrator_can_move_a_contact_to_a_different_client(): void
    {
        $this->actingAsAdministrator();
        $originalClient = Client::factory()->create();
        $newClient = Client::factory()->create();
        $contact = Contact::factory()->create(['client_id' => $originalClient->id]);

        $this->putJson("/api/v1/contacts/{$contact->public_id}", ['client_id' => $newClient->public_id])
            ->assertOk()
            ->assertJson(['data' => ['client' => ['public_id' => $newClient->public_id]]]);

        $this->assertSame($newClient->id, $contact->fresh()->client_id);
    }

    public function test_administrator_can_delete_a_contact(): void
    {
        $this->actingAsAdministrator();
        $contact = Contact::factory()->create();

        $this->deleteJson("/api/v1/contacts/{$contact->public_id}")->assertNoContent();

        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
    }

    // --- Primary contact enforcement -------------------------------------

    public function test_marking_a_contact_primary_clears_any_previous_primary_contact_for_the_same_client(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $existingPrimary = Contact::factory()->primary()->create(['client_id' => $client->id]);
        $newContact = Contact::factory()->create(['client_id' => $client->id]);

        $this->putJson("/api/v1/contacts/{$newContact->public_id}", ['is_primary' => true])
            ->assertOk()
            ->assertJson(['data' => ['is_primary' => true]]);

        $this->assertFalse($existingPrimary->fresh()->is_primary);
        $this->assertTrue($newContact->fresh()->is_primary);
    }

    public function test_a_new_primary_contact_can_be_created_directly(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        $existingPrimary = Contact::factory()->primary()->create(['client_id' => $client->id]);

        $this->postJson('/api/v1/contacts', [
            'client_id' => $client->public_id,
            'first_name' => 'New',
            'last_name' => 'Primary',
            'is_primary' => true,
        ])->assertCreated()->assertJson(['data' => ['is_primary' => true]]);

        $this->assertFalse($existingPrimary->fresh()->is_primary);
        $this->assertSame(1, Contact::query()->where('client_id', $client->id)->where('is_primary', true)->count());
    }

    public function test_setting_a_contact_as_primary_does_not_affect_other_clients_primary_contact(): void
    {
        $this->actingAsAdministrator();
        $otherClientsPrimary = Contact::factory()->primary()->create();
        $client = Client::factory()->create();
        $contact = Contact::factory()->create(['client_id' => $client->id]);

        $this->putJson("/api/v1/contacts/{$contact->public_id}", ['is_primary' => true])->assertOk();

        $this->assertTrue($otherClientsPrimary->fresh()->is_primary);
    }

    // --- Filters / search ------------------------------------------------

    public function test_contacts_can_be_filtered_by_client(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();
        Contact::factory()->create(['client_id' => $client->id, 'first_name' => 'Belongs']);
        Contact::factory()->create(['first_name' => 'Elsewhere']);

        $this->getJson("/api/v1/contacts?client={$client->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['first_name' => 'Belongs']]]);
    }

    public function test_contacts_can_be_filtered_by_status(): void
    {
        $this->actingAsAdministrator();
        Contact::factory()->create(['first_name' => 'Active One']);
        Contact::factory()->inactive()->create(['first_name' => 'Inactive One']);

        $this->getJson('/api/v1/contacts?status=inactive')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['first_name' => 'Inactive One']]]);
    }

    public function test_contacts_can_be_filtered_by_primary_status(): void
    {
        $this->actingAsAdministrator();
        Contact::factory()->primary()->create(['first_name' => 'Primary One']);
        Contact::factory()->create(['first_name' => 'Non Primary']);

        $this->getJson('/api/v1/contacts?is_primary=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['first_name' => 'Primary One']]]);
    }

    public function test_contacts_can_be_searched_by_name_or_email(): void
    {
        $this->actingAsAdministrator();
        Contact::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.test']);
        Contact::factory()->create(['first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john@example.test']);

        $this->getJson('/api/v1/contacts?q=Doe')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['last_name' => 'Doe']]]);

        $this->getJson('/api/v1/contacts?q=john@example.test')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['first_name' => 'John']]]);
    }
}
