<?php

declare(strict_types=1);

use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(AssertsTenantIsolation::class)->in('Feature');
