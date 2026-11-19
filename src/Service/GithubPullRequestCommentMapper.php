<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestComment;

class GithubPullRequestCommentMapper
{
    /**
     * Keeps at most $limit rows, then reverses them.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return PullRequestComment[]
     */
    public function mapComments(array $rows, int $limit): array
    {
        $comments = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $comments[] = $this->mapComment($row);
        }

        return array_reverse($comments);
    }

    /**
     * Maps review rows to DTOs in reverse order, skipping reviews without a body and keeping at most $limit.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return PullRequestComment[]
     */
    public function mapReviews(array $rows, int $limit): array
    {
        $reviews = [];
        foreach ($rows as $row) {
            $dto = $this->mapReview($row);
            if ($dto !== null) {
                $reviews[] = $dto;
                if (count($reviews) >= $limit) {
                    break;
                }
            }
        }

        return array_reverse($reviews);
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function mapReview(array $row): ?PullRequestComment
    {
        $body = isset($row['body']) ? trim((string) $row['body']) : '';
        if ($body === '') {
            return null;
        }
        $user = $row['user'] ?? [];
        $author = is_array($user) && isset($user['login']) ? (string) $user['login'] : 'unknown';
        $date = new \DateTimeImmutable((string) ($row['submitted_at'] ?? $row['created_at'] ?? 'now'));

        return new PullRequestComment($author, $date, $body, null, null);
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function mapComment(array $row): PullRequestComment
    {
        $user = $row['user'] ?? [];
        $author = is_array($user) && isset($user['login']) ? (string) $user['login'] : 'unknown';
        $createdAt = isset($row['created_at']) ? new \DateTimeImmutable((string) $row['created_at']) : new \DateTimeImmutable();
        $body = isset($row['body']) ? (string) $row['body'] : '';
        $path = isset($row['path']) ? (string) $row['path'] : null;
        $line = isset($row['line']) ? (int) $row['line'] : null;

        return new PullRequestComment($author, $createdAt, $body, $path, $line);
    }
}
