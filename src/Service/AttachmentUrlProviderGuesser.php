<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\IssueTrackerProvider;

/**
 * Infers work-item provider from attachment content URL host (SCI-197).
 */
final class AttachmentUrlProviderGuesser
{
    public function guess(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $host = parse_url(trim($url), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $normalizedHost = strtolower($host);

        if (str_ends_with($normalizedHost, '.atlassian.net')) {
            return IssueTrackerProvider::Jira->value;
        }

        if ($normalizedHost === 'linear.app' || str_ends_with($normalizedHost, '.linear.app')) {
            return IssueTrackerProvider::Linear->value;
        }

        return null;
    }
}
