<?php

declare(strict_types=1);

namespace P1Bot\Support;

use DateTimeImmutable;

interface Clock
{
    /** Current time in UTC. */
    public function now(): DateTimeImmutable;
}
