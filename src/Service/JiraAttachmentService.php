<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches issue attachment metadata and downloads attachment bytes using the same Jira-authenticated HTTP client as {@see JiraService}.
 */
class JiraAttachmentService
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $jiraBaseUrl
    ) {
    }

    /**
     * Returns attachment filename and content URL for each attachment on the issue.
     *
     * @return list<array{filename: string, contentUrl: string}>
     *
     * @throws ApiException When the issue cannot be loaded
     */
    public function fetchAttachmentsForIssue(string $issueKey): array
    {
        $key = rawurlencode($issueKey);
        $response = $this->client->request('GET', "/rest/api/3/issue/{$key}?fields=attachment");

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Could not load attachments for issue \"{$issueKey}\".",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();
        $attachments = $data['fields']['attachment'] ?? [];
        if (! is_array($attachments)) {
            return [];
        }

        return $this->collectValidAttachments($attachments);
    }

    /**
     * @param array<int, mixed> $attachments
     * @return list<array{filename: string, contentUrl: string}>
     */
    private function collectValidAttachments(array $attachments): array
    {
        $out = [];
        foreach ($attachments as $row) {
            $entry = $this->normalizeAttachmentRow($row);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @return array{filename: string, contentUrl: string}|null
     */
    private function normalizeAttachmentRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }
        $filename = isset($row['filename']) && is_string($row['filename']) ? $row['filename'] : 'attachment';
        $content = $row['content'] ?? null;
        if (! is_string($content) || $content === '') {
            return null;
        }

        return ['filename' => $filename, 'contentUrl' => $content];
    }

    /**
     * Downloads raw attachment bytes. Enforces same host and Jira attachment content path as configured Jira.
     *
     * @throws ApiException On HTTP errors or when the URL is not allowed
     */
    public function downloadAttachmentContent(string $contentUrl): string
    {
        $requestTarget = $this->buildRequestTarget($contentUrl);
        $this->assertAllowedAttachmentEndpoint($requestTarget);

        $response = $this->client->request('GET', $requestTarget, [
            'headers' => [
                'Accept' => '*/*',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                'Attachment download failed.',
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        return $response->getContent();
    }

    /**
     * @throws ApiException When the URL is not an allowed Jira attachment content URL
     */
    private function assertAllowedAttachmentEndpoint(string $requestTarget): void
    {
        if (str_starts_with($requestTarget, '/')) {
            $this->assertRelativePathIsAttachmentEndpoint($requestTarget);

            return;
        }

        $this->assertAbsoluteUrlIsAttachmentEndpoint($requestTarget);
    }

    /**
     * @throws ApiException
     */
    private function assertRelativePathIsAttachmentEndpoint(string $requestTarget): void
    {
        if (! str_contains($requestTarget, '/rest/api/3/attachment/content/')) {
            throw new ApiException(
                'Attachment URL path must include /rest/api/3/attachment/content/.',
                '',
                400
            );
        }
    }

    /**
     * @throws ApiException
     */
    private function assertAbsoluteUrlIsAttachmentEndpoint(string $requestTarget): void
    {
        $parts = parse_url($requestTarget);
        if ($parts === false || ! isset($parts['host'], $parts['path'])) {
            throw new ApiException('Invalid attachment URL.', '', 400);
        }

        $baseParts = parse_url(rtrim($this->jiraBaseUrl, '/') . '/');
        if ($baseParts === false || ! isset($baseParts['host'])) {
            throw new ApiException('Invalid Jira base URL configuration.', '', 500);
        }

        if (strcasecmp((string) $parts['host'], (string) $baseParts['host']) !== 0) {
            throw new ApiException(
                'Attachment URL host must match the configured Jira instance.',
                '',
                400
            );
        }

        if (! str_contains((string) $parts['path'], '/rest/api/3/attachment/content/')) {
            throw new ApiException(
                'URL must be a Jira REST attachment content endpoint.',
                '',
                400
            );
        }
    }

    private function buildRequestTarget(string $contentUrl): string
    {
        $trimmed = trim($contentUrl);
        if ($trimmed === '') {
            return '';
        }

        return $trimmed;
    }

    private function extractTechnicalDetails(ResponseInterface $response): string
    {
        $statusCode = $response->getStatusCode();
        $responseBody = 'No response body';

        try {
            $content = $response->getContent(false);
            if ($content !== '') {
                $responseBody = mb_strlen($content) > 500
                    ? mb_substr($content, 0, 500) . '... (truncated)'
                    : $content;
            }
            // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            $responseBody = 'Unable to read response body: ' . $e->getMessage();
        }
        // @codeCoverageIgnoreEnd

        return sprintf('HTTP %d: %s', $statusCode, $responseBody);
    }
}
