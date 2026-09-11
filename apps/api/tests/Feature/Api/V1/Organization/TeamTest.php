<?php

namespace Tests\Feature\Api\V1\Organization;

use App\Models\Department;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_administrator_can_create_a_team_without_a_department(): void
    {
        $this->actingAsAdministrator();

        $response = $this->postJson('/api/v1/teams', ['name' => 'Special Projects']);

        $response->assertCreated()->assertJson([
            'data' => ['name' => 'Special Projects', 'department' => null],
        ]);

        $this->assertDatabaseHas('teams', ['name' => 'Special Projects', 'department_id' => null]);
    }

    public function test_administrator_can_create_a_team_assigned_to_a_department(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();

        $response = $this->postJson('/api/v1/teams', [
            'name' => 'Backend',
            'department_id' => $department->public_id,
        ]);

        $response->assertCreated()->assertJson([
            'data' => [
                'name' => 'Backend',
                'department' => ['public_id' => $department->public_id, 'name' => $department->name],
            ],
        ]);

        // The internal numeric id is what's actually persisted as the
        // foreign key — never the public_id string (DEC-017).
        $this->assertDatabaseHas('teams', ['name' => 'Backend', 'department_id' => $department->id]);
    }

    public function test_creating_a_team_with_an_unknown_department_public_id_is_rejected(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/teams', ['name' => 'Backend', 'department_id' => 'not-a-real-ulid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_team_names_must_be_unique_within_the_same_department(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        Team::factory()->create(['department_id' => $department->id, 'name' => 'Alpha']);

        $this->postJson('/api/v1/teams', ['name' => 'Alpha', 'department_id' => $department->public_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_team_names_can_repeat_across_different_departments(): void
    {
        $this->actingAsAdministrator();
        $departmentA = Department::factory()->create();
        $departmentB = Department::factory()->create();
        Team::factory()->create(['department_id' => $departmentA->id, 'name' => 'Alpha']);

        $this->postJson('/api/v1/teams', ['name' => 'Alpha', 'department_id' => $departmentB->public_id])
            ->assertCreated();
    }

    public function test_team_names_must_be_unique_among_teams_with_no_department(): void
    {
        $this->actingAsAdministrator();
        Team::factory()->create(['department_id' => null, 'name' => 'Alpha']);

        $this->postJson('/api/v1/teams', ['name' => 'Alpha'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_administrator_can_reassign_a_teams_department(): void
    {
        $this->actingAsAdministrator();
        $team = Team::factory()->create(['department_id' => null]);
        $department = Department::factory()->create();

        $this->putJson("/api/v1/teams/{$team->public_id}", ['department_id' => $department->public_id])
            ->assertOk()
            ->assertJson(['data' => ['department' => ['public_id' => $department->public_id]]]);

        $this->assertSame($department->id, $team->fresh()->department_id);
    }

    public function test_administrator_can_clear_a_teams_department(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        $team = Team::factory()->create(['department_id' => $department->id]);

        $this->putJson("/api/v1/teams/{$team->public_id}", ['department_id' => null])
            ->assertOk()
            ->assertJson(['data' => ['department' => null]]);

        $this->assertNull($team->fresh()->department_id);
    }

    public function test_teams_can_be_filtered_by_department(): void
    {
        $this->actingAsAdministrator();
        $departmentA = Department::factory()->create();
        $departmentB = Department::factory()->create();
        Team::factory()->create(['department_id' => $departmentA->id, 'name' => 'In A']);
        Team::factory()->create(['department_id' => $departmentB->id, 'name' => 'In B']);

        $this->getJson("/api/v1/teams?department={$departmentA->public_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['name' => 'In A']]]);
    }

    public function test_filtering_teams_by_an_unknown_department_returns_an_empty_list(): void
    {
        $this->actingAsAdministrator();
        Team::factory()->create();

        $this->getJson('/api/v1/teams?department=not-a-real-ulid')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_deleting_a_team_does_not_affect_its_department(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        $team = Team::factory()->create(['department_id' => $department->id]);

        $this->deleteJson("/api/v1/teams/{$team->public_id}")->assertNoContent();

        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }
}
