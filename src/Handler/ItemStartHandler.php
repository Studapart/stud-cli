<?php

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\JiraService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemStartHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly string $baseBranch
    ) {
    }

    public function handle(SymfonyStyle $io, string $key): int
    {
        $key = strtoupper($key);
        $io->section("Starting work on {$key}");

        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>Fetching details for issue: {$key}</>");
        }
        
        try {
            $issue = $this->jiraService->getIssue($key);
        } catch (\Exception $e) {
            $io->error("Could not find Jira issue with key \"{$key}\".");
            return 1;
        }

        $prefix = $this->getBranchPrefixFromIssueType($issue->issueType);
        $slug = $this->slugify($issue->title);
        $branchName = "{$prefix}/{$key}-{$slug}";

        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>Generated branch name: {$branchName}</>");
        }

        $io->text("Fetching latest changes from origin...");
        $this->gitRepository->fetch();

        $io->text("Creating new branch: <info>{$branchName}</info>");
        $this->gitRepository->createBranch($branchName, $this->baseBranch);

        $io->success("Branch '{$branchName}' created from '" . $this->baseBranch . "'.");

        return 0;
    }

    private function getBranchPrefixFromIssueType(string $issueType): string
    {
        return match (strtolower($issueType)) {
            'bug' => 'fix',
            'story', 'epic' => 'feat',
            'task', 'sub-task' => 'chore',
            default => 'feat',
        };
    }

    private function slugify(string $string): string
    {
        // Lowercase, remove accents, remove non-word chars, and replace spaces with hyphens.
        $string = strtolower(trim($string));
        $string = preg_replace('/[^a-z0-9-]+/', '-', $string); // Replace non-alphanumeric characters (except hyphens) with a single hyphen
        $string = preg_replace('/-+/', '-', $string); // Replace multiple hyphens with a single hyphen
        return trim($string, '-');
    }
}
