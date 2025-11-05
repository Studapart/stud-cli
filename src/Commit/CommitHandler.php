<?php

namespace App\Commit;

use App\Git\GitRepository;
use App\Jira\JiraService;
use Symfony\Component\Console\Style\SymfonyStyle;

class CommitHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly string $baseBranch
    ) {
    }

    public function handle(SymfonyStyle $io, bool $isNew, ?string $message): int
    {
        $io->section('Conventional Commit Helper');

        // If a message is provided via the -m flag, use it directly.
        if (!empty($message)) {
            $io->text('Staging all changes...');
            $this->gitRepository->stageAllChanges();

            $io->text('Committing with provided message...');
            $this->gitRepository->commit($message);

            $io->success('Commit created successfully!');
            return 0;
        }

        // 1. Auto-Fixup Strategy: Find the latest logical commit
        $latestLogicalSha = null;
        if (!$isNew) {
            if ($io->isVerbose()) {
                $io->writeln('  <fg=gray>Checking for previous logical commit...</>');
            }
            $latestLogicalSha = $this->gitRepository->findLatestLogicalSha($this->baseBranch);
            if ($latestLogicalSha && $io->isVerbose()) {
                $io->writeln("  <fg=gray>Found logical commit SHA: {$latestLogicalSha}</>");
            }
        }

        // 2. If a logical commit is found and --new is not used, create a fixup commit
        if ($latestLogicalSha) {
            $io->text('Staging all changes...');
            $this->gitRepository->stageAllChanges();

            $io->text("Creating fixup commit for <info>{$latestLogicalSha}</info>...");
            $this->gitRepository->commitFixup($latestLogicalSha);

            $io->success("✅ Changes saved as a fixup for commit {$latestLogicalSha}.");
            return 0;
        }

        // 3. If no logical commit is found OR --new is used, run the interactive prompter
        $io->note('No previous logical commit found or --new flag used. Starting interactive prompter...');

        $key = $this->gitRepository->getJiraKeyFromBranchName();
        if (!$key) {
            $io->error([
                'Could not find a Jira key in your current branch name.',
                'Please use "stud start <key>" to create a branch.',
            ]);
            return 1;
        }

        try {
            if ($io->isVerbose()) {
                $io->writeln("  <fg=gray>Fetching Jira issue: {$key}</>");
            }
            // The getIssue method now fetches components as well
            $issue = $this->jiraService->getIssue($key);
        } catch (\Exception $e) {
            $io->error("Could not find Jira issue with key \"{$key}\".");
            return 1;
        }

        $detectedType = $this->getCommitTypeFromIssueType($issue->issueType);
        $detectedSummary = $issue->title;

        if ($io->isVeryVerbose()) {
            $io->writeln("  <fg=gray>Jira Issue Details:</>");
            $io->writeln("    <fg=gray>Title: {$issue->title}</>");
            $io->writeln("    <fg=gray>Type: {$issue->issueType} -> {$detectedType}</>");
            $io->writeln("    <fg=gray>Components: " . implode(', ', $issue->components) . "</>");
        }

        // 4. Upgraded Interactive Prompter with Scope Inference
        $scopePrompt = 'Scope (optional)';
        $defaultScope = null;
        if (!empty($issue->components)) {
            $defaultScope = $issue->components[0]; // Use the first component name
            $scopePrompt = "Scope (auto-detected '{$defaultScope}')";
        }

        $type = $io->ask("Commit Type (auto-detected '{$detectedType}')", $detectedType);
        $scope = $io->ask($scopePrompt, $defaultScope);
        $summary = $io->ask("Short Message (auto-filled from Jira)", $detectedSummary);

        // 5. Assemble commit message according to the new template
        $commitMessage = "{$type}" . ($scope ? "({$scope})" : "") . ": {$summary} [{$key}]";

        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>Generated commit message:</>\n{$commitMessage}");
        }

        $io->text('Staging all changes...');
        $this->gitRepository->stageAllChanges();

        $io->text('Committing...');
        $this->gitRepository->commit($commitMessage);

        $io->success('Commit created successfully!');
        
        return 0;
    }

    private function getCommitTypeFromIssueType(string $issueType): string
    {
        return match (strtolower($issueType)) {
            'bug' => 'fix',
            'story', 'epic' => 'feat',
            'task', 'sub-task' => 'chore',
            default => 'feat',
        };
    }
}
