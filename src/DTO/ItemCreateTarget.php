<?php

declare(strict_types=1);

namespace App\DTO;

use App\Service\IssueTrackerPort;

/**
 * The tracker port and project key one create run uses. Decided once, after the project lookup.
 */
final class ItemCreateTarget
{
    public function __construct(
        public readonly IssueTrackerPort $port,
        public readonly string $projectKey,
        public readonly string $provider,
    ) {
    }
}
