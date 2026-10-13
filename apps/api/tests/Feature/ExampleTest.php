<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The site root opens the Staff Web Portal (Phase 34A), which sends a
     * guest on to the login page.
     */
    public function test_the_site_root_opens_the_portal(): void
    {
        $this->get('/')->assertRedirect('/app');
        $this->get('/app')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }
}
