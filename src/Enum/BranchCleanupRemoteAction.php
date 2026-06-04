<?php

declare(strict_types=1);

namespace App\Enum;

enum BranchCleanupRemoteAction: string
{
    case Skip = 'skip';
    case PromptDelete = 'prompt_delete';
    case KeepQuiet = 'keep_quiet';
    case Manual = 'manual';
}
