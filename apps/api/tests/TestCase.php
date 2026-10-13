<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pages load their CSS/JS through @vite (Phase 34A); tests render
        // them without a built manifest, as CI does not run `npm run build`.
        $this->withoutVite();
    }
}
