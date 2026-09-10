<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'status' => 'ok',
                ],
            ])
            ->assertJsonStructure([
                'data' => ['status', 'timestamp'],
            ]);
    }

    public function test_health_endpoint_does_not_expose_sensitive_details(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertJsonMissingPath('data.env')
            ->assertJsonMissingPath('data.debug')
            ->assertJsonMissingPath('data.database')
            ->assertJsonMissingPath('data.version');
    }
}
