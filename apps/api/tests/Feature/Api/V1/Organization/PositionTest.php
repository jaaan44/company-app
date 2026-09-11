<?php

namespace Tests\Feature\Api\V1\Organization;

use App\Models\Department;
use App\Models\Position;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PositionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_administrator_can_create_a_position_independent_of_any_department(): void
    {
        $this->actingAsAdministrator();

        $response = $this->postJson('/api/v1/positions', ['title' => 'Software Engineer']);

        $response->assertCreated()->assertJson([
            'data' => ['title' => 'Software Engineer', 'department' => null],
        ]);

        $this->assertDatabaseHas('positions', ['title' => 'Software Engineer', 'department_id' => null]);
    }

    public function test_administrator_can_create_a_position_scoped_to_a_department(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();

        $response = $this->postJson('/api/v1/positions', [
            'title' => 'Engineering Manager',
            'department_id' => $department->public_id,
        ]);

        $response->assertCreated()->assertJson([
            'data' => [
                'title' => 'Engineering Manager',
                'department' => ['public_id' => $department->public_id],
            ],
        ]);

        $this->assertDatabaseHas('positions', [
            'title' => 'Engineering Manager',
            'department_id' => $department->id,
        ]);
    }

    public function test_creating_a_position_with_an_unknown_department_public_id_is_rejected(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/positions', ['title' => 'Manager', 'department_id' => 'not-a-real-ulid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_the_same_position_title_can_exist_in_multiple_departments(): void
    {
        $this->actingAsAdministrator();
        $departmentA = Department::factory()->create();
        $departmentB = Department::factory()->create();
        Position::factory()->create(['department_id' => $departmentA->id, 'title' => 'Manager']);

        $this->postJson('/api/v1/positions', ['title' => 'Manager', 'department_id' => $departmentB->public_id])
            ->assertCreated();
    }

    public function test_a_position_title_must_be_unique_within_the_same_department(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        Position::factory()->create(['department_id' => $department->id, 'title' => 'Manager']);

        $this->postJson('/api/v1/positions', ['title' => 'Manager', 'department_id' => $department->public_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_administrator_can_update_a_position(): void
    {
        $this->actingAsAdministrator();
        $position = Position::factory()->create(['title' => 'Old Title']);

        $this->putJson("/api/v1/positions/{$position->public_id}", ['title' => 'New Title', 'status' => 'inactive'])
            ->assertOk()
            ->assertJson(['data' => ['title' => 'New Title', 'status' => 'inactive']]);
    }

    public function test_positions_can_be_filtered_by_department(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        Position::factory()->create(['department_id' => $department->id, 'title' => 'In Department']);
        Position::factory()->create(['department_id' => null, 'title' => 'Standalone']);

        $this->getJson("/api/v1/positions?department={$department->public_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['title' => 'In Department']]]);
    }

    public function test_administrator_can_delete_a_position(): void
    {
        $this->actingAsAdministrator();
        $position = Position::factory()->create();

        $this->deleteJson("/api/v1/positions/{$position->public_id}")->assertNoContent();

        $this->assertDatabaseMissing('positions', ['id' => $position->id]);
    }

    public function test_deleting_a_position_does_not_affect_its_department(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();
        $position = Position::factory()->create(['department_id' => $department->id]);

        $this->deleteJson("/api/v1/positions/{$position->public_id}")->assertNoContent();

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    public function test_deleting_a_position_with_staff_is_rejected(): void
    {
        $this->actingAsAdministrator();
        $position = Position::factory()->create();
        Staff::factory()->create(['position_id' => $position->id]);

        $this->deleteJson("/api/v1/positions/{$position->public_id}")->assertStatus(409);

        $this->assertDatabaseHas('positions', ['id' => $position->id]);
    }
}
