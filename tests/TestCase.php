<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\UsesMigratedDatabase;

abstract class TestCase extends BaseTestCase
{
    use UsesMigratedDatabase;

    protected function setUp(): void
    {
        // La base debe estar migrada antes de que DatabaseTransactions abra la transacción.
        $this->refreshApplication();
        $this->setUpUsesMigratedDatabase();

        parent::setUp();
    }
}
