<?php

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemStartHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly string $baseBranch,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io, string $key): int
    {
        $key = strtoupper($key);
        $io->section($this->translator->trans('item.start.section', ['key' => $key]));

        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>{$this->translator->trans('item.start.fetching', ['key' => $key])}</>");
        }
        
        try {
            $issue = $this->jiraService->getIssue($key);
        } catch (\Exception $e) {
            $io->error($this->translator->trans('item.start.error_not_found', ['key' => $key]));
            return 1;
        }

        $prefix = $this->getBranchPrefixFromIssueType($issue->issueType);
        $slug = $this->slugify($issue->title);
        $branchName = "{$prefix}/{$key}-{$slug}";

        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>{$this->translator->trans('item.start.generated_branch', ['branch' => $branchName])}</>");
        }

        $io->text($this->translator->trans('item.start.fetching_changes'));
        $this->gitRepository->fetch();

        $io->text($this->translator->trans('item.start.creating_branch', ['branch' => $branchName]));
        $this->gitRepository->createBranch($branchName, $this->baseBranch);

        $io->success($this->translator->trans('item.start.success', ['branch' => $branchName, 'base' => $this->baseBranch]));

        return 0;
    }

    protected function getBranchPrefixFromIssueType(string $issueType): string
    {
        return match (strtolower($issueType)) {
            'bug' => 'fix',
            'story', 'epic' => 'feat',
            'task', 'sub-task' => 'chore',
            default => 'feat',
        };
    }

    protected function slugify(string $string): string
    {
        // Lowercase, remove accents, remove non-word chars, and replace spaces with hyphens.
        $string = strtolower(trim($string));
        $string = preg_replace('/[^a-z0-9-]+/', '-', $string); // Replace non-alphanumeric characters (except hyphens) with a single hyphen
        $string = preg_replace('/-+/', '-', $string); // Replace multiple hyphens with a single hyphen
        return trim($string, '-');
    }
}
