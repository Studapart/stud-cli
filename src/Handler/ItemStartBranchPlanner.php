<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\WorkItem;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ItemStartBranchPlanner
{
    public function __construct(private readonly BranchActionResolver $branchActionResolver)
    {
    }

    public function branchName(WorkItem $issue, string $key, string $prefix): string
    {
        $slugger = new AsciiSlugger();
        $slugValue = $slugger->slug($issue->title)->lower()->toString();

        return "{$prefix}/{$key}-{$slugValue}";
    }

    /**
     * @param array{local: array<string>, remote: array<string>} $existingBranches
     * @return array{action: string, branch: string}
     */
    public function resolveAction(string $branchName, array $existingBranches): array
    {
        return $this->branchActionResolver->resolve($branchName, $existingBranches);
    }
}
