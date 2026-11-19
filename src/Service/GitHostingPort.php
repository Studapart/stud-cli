<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestData;
use App\DTO\PullRequestFeedbackIds;

/**
 * Interface for Git hosting provider implementations (GitHub, GitLab, etc.).
 *
 * Extends the read contract with the methods that change remote data. Adapters implement this
 * interface; lookup handlers depend on {@see GitHostingReadPort}.
 *
 * This interface defines the contract that all Git provider implementations must follow,
 * ensuring consistent behavior across different hosting platforms.
 */
interface GitHostingPort extends GitHostingReadPort
{
    /**
     * Creates a pull request (GitHub) or merge request (GitLab).
     *
     * @return array<string, mixed> The created PR/MR data
     */
    public function createPullRequest(PullRequestData $prData): array;

    /**
     * Assigns a pull request or merge request to the authenticated provider user.
     *
     * @param array<string, mixed> $prData Normalized PR/MR data returned by create/find.
     */
    public function assignPullRequestToAuthor(array $prData): void;

    /**
     * Adds labels to a pull request.
     *
     * @param int $issueNumber The PR/MR number
     * @param array<string> $labels Array of label names to add
     */
    public function addLabelsToPullRequest(int $issueNumber, array $labels): void;

    /**
     * Creates a comment on a pull request.
     *
     * @param int $issueNumber The PR/MR number
     * @param string $body The comment body
     * @return array<string, mixed> The created comment data
     */
    public function createComment(int $issueNumber, string $body): array;

    /**
     * Replies to a threaded PR/MR feedback target.
     *
     * @return array<string, mixed> The created reply data
     */
    public function replyToPullRequestFeedback(int $pullNumber, PullRequestFeedbackIds $targetIds, string $body): array;

    /**
     * Resolves a threaded PR/MR feedback target.
     *
     * @return array<string, mixed> The resolved target data
     */
    public function resolvePullRequestFeedback(int $pullNumber, PullRequestFeedbackIds $targetIds): array;

    /**
     * Updates a pull request (e.g., draft/WIP status).
     *
     * @param int $pullNumber The PR/MR number
     * @param bool $draft Whether the PR should be in draft/WIP status
     * @return array<string, mixed> The updated PR data
     */
    public function updatePullRequest(int $pullNumber, bool $draft): array;

    /**
     * Creates a new label in the repository.
     *
     * @param string $name The label name
     * @param string $color The label color (hex format)
     * @param string|null $description Optional label description
     * @return array<string, mixed> The created label data
     */
    public function createLabel(string $name, string $color, ?string $description = null): array;
}
