<?php

declare(strict_types=1);

namespace App\Handler;

/**
 * Which tracker prompts a project setup run asks. Decided once, right after the provider choice.
 */
final class ProjectInitTrackers
{
    public function __construct(
        public readonly bool $jira,
        public readonly bool $linear,
        public readonly bool $linearTeamKey,
    ) {
    }
}
