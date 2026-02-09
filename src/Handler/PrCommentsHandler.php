<?php

declare(strict_types=1);

namespace App\Handler;

use App\Response\PrCommentsResponse;
use App\Service\GitProviderInterface;
use App\Service\GitRepository;
use App\Service\TranslationService;

/**
 * Fetches and aggregates PR/MR comments (issue and review) for the current branch's open PR.
 * Returns a Response DTO; no I/O. Same PR resolution logic as PrCommentHandler.
 */
class PrCommentsHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly ?GitProviderInterface $gitProvider,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(): PrCommentsResponse
    {
        if (! $this->gitProvider) {
            return PrCommentsResponse::error($this->translator->trans('pr.comments.error_no_provider'));
        }

        $pr = $this->findActivePullRequest();
        if ($pr === null) {
            return PrCommentsResponse::error($this->translator->trans('pr.comments.error_no_pr'));
        }

        $prNumber = (int) ($pr['number'] ?? 0);
        if ($prNumber <= 0) {
            return PrCommentsResponse::error($this->translator->trans('pr.comments.error_no_pr'));
        }

        try {
            $issueComments = $this->gitProvider->getPullRequestComments($prNumber);
            $reviewComments = $this->gitProvider->getPullRequestReviewComments($prNumber);
            $reviews = $this->gitProvider->getPullRequestReviews($prNumber);

            return PrCommentsResponse::success($issueComments, $reviewComments, $reviews, $prNumber);
        } catch (\Exception $e) {
            return PrCommentsResponse::error(
                $this->translator->trans('pr.comments.error_fetch', ['error' => $e->getMessage()])
            );
        }
    }

    /**
     * Finds the active Pull Request for the current branch.
     *
     * @return array<string, mixed>|null PR data or null if not found
     */
    protected function findActivePullRequest(): ?array
    {
        $branch = $this->gitRepository->getCurrentBranchName();
        $remoteOwner = $this->gitRepository->getRepositoryOwner('origin');
        $headBranch = $remoteOwner ? "{$remoteOwner}:{$branch}" : $branch;

        return $this->gitProvider->findPullRequestByBranch($headBranch);
    }
}
