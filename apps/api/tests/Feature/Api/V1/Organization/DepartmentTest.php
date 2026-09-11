<?php

namespace Tests\Feature\Api\V1\Organization;

use App\Enums\OrganizationStatus;
use App\Models\Department;
use App\Models\Position;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_administrator_can_list_departments(): void
    {
        $this->actingAsAdministrator();
        Department::factory()->count(2)->create();

        $this->getJson('/api/v1/departments')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_administrator_can_create_a_department(): void
    {
        $this->actingAsAdministrator();

        $response = $this->postJson('/api/v1/departments', [
            'name' => 'Engineering',
            'description' => 'Builds the product.',
        ]);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'name' => 'Engineering',
                    'status' => 'active',
                ],
            ])
            ->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('departments', ['name' => 'Engineering']);
        $publicId = $response->json('data.public_id');
        $this->assertNotNull($publicId);
        $this->assertNotSame((string) Department::first()->id, $publicId);
    }

    public function test_creating_a_department_requires_a_unique_name(): void
    {
        $this->actingAsAdministrator();
        Department::factory()->create(['name' => 'Engineering']);

        $this->postJson('/api/v1/departments', ['name' => 'Engineering'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_creating_a_department_requires_a_name(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/departments', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_administrator_can_view_a_single_department(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();

        $this->getJson("/api/v1/departments/{$department->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['public_id' => $department->public_id]]);
    }

    public function test_viewing_a_department_by_its_internal_numeric_id_fails(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();

        $this->getJson("/api/v1/departments/{$department->id}")->assertNotFound();
    }

    public function test_administrator_can_update_a_department(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create(['name' => 'Old Name']);

        $this->putJson("/api/v1/departments/{$department->public_id}", [
            'name' => 'New Name',
            'status' => 'inactive',
            'sort_order' => 5,
        ])->assertOk()->assertJson([
            'data' => ['name' => 'New Name', 'status' => 'inactive', 'sort_order' => 5],
        ]);

        $this->assertSame(OrganizationStatus::Inactive, $department->fresh()->status);
    }

    public function test_updating_a_department_to_a_duplicate_name_is_rejected(): void
    {
        $this->actingAsAdministrator();
        Department::factory()->create(['name' => 'Taken']);
        $department = Department::factory()->create(['name' => 'Mine']);

        $this->putJson("/api/v1/departments/{$department->public_id}", ['name' => 'Taken'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_updating_a_department_without_changing_its_name_is_allowed(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create(['name' => 'Unchanged']);

        $this->putJson("/api/v1/departments/{$department->public_id}", ['name' => 'Unchanged', 'sort_order' => 3])
            ->assertOk();
    }

    public function test_administrator_can_delete_a_department_with_no_dependents(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();

        $this->deleteJson("/api/v1/departments/{$department->public_id}")->assertNoContent();

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }

    public function test_deleting_a_department_with_teams_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        Team::factory()->create(['department_id' => $department->id]);

        $this->deleteJson("/api/v1/departments/{$department->public_id}")->assertStatus(409);

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    public function test_deleting_a_department_with_positions_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        Position::factory()->create(['department_id' => $department->id]);

        $this->deleteJson("/api/v1/departments/{$department->public_id}")->assertStatus(409);

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    public function test_departments_can_be_filtered_by_status(): void
    {
        $this->actingAsAdministrator();
        Department::factory()->create(['name' => 'Active One']);
        Department::factory()->inactive()->create(['name' => 'Inactive One']);

        $response = $this->getJson('/api/v1/departments?status=inactive');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['name' => 'Inactive One']]]);
    }

    public function test_departments_report_their_team_and_position_counts(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        Team::factory()->count(2)->create(['department_id' => $department->id]);
        Position::factory()->create(['department_id' => $department->id]);

        $this->getJson("/api/v1/departments/{$department->public_id}")
            ->assertOk()
            ->assertJson(['data' => ['teams_count' => 2, 'positions_count' => 1]]);
    }
}
