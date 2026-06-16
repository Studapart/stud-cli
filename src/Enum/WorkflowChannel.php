<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * CLI output coloring channel for workflow progress lines and text blocks.
 */
enum WorkflowChannel
{
    case Default;
    case Jira;
    case Git;
}
