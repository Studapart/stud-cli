<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\BranchListRow;
use App\Response\BranchListResponse;
use App\Service\BranchDeletionEligibilityResolver;
use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Service\TranslationService;

class BranchListHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitBranchService $gitBranchService,
        private readonly BranchDeletionEligibilityResolver $eligibilityResolver,
        private readonly ?string $configuredBaseBranch,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(): BranchListResponse
    {
        $branches = $this->gitBranchService->getAllLocalBranches();

        if (empty($branches)) {
            return BranchListResponse::success([]);
        }

        $remoteBranches = $this->gitBranchService->getAllRemoteBranches('origin');
        $remoteBranchesSet = array_flip($remoteBranches);
        $prSnapshot = $this->eligibilityResolver->buildPullRequestSnapshot();
        $baseBranch = $this->eligibilityResolver->resolveBaseBranch($this->configuredBaseBranch);
        $currentBranch = $this->gitRepository->getCurrentBranchName();

        $rows = [];
        foreach ($branches as $branch) {
            $isCurrent = $branch === $currentBranch;
            $remoteExists = isset($remoteBranchesSet[$branch]);
            $eligibility = $this->eligibilityResolver->evaluate(
                $branch,
                $currentBranch,
                $remoteExists,
                $baseBranch,
                $prSnapshot['map'],
                $prSnapshot['available']
            );

            $branchDisplay = $branch;
            if ($isCurrent) {
                $branchDisplay = "{$branch} (current)";
            }

            $rows[] = new BranchListRow(
                $branchDisplay,
                $this->translator->trans("branches.list.status.{$eligibility->status}"),
                $this->translator->trans("branches.list.auto_clean.{$eligibility->decision->value}"),
                $remoteExists ? '✓' : '✗',
                $eligibility->hasPullRequest ? '✓' : '✗'
            );
        }

        return BranchListResponse::success($rows);
    }
}
