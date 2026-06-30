<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Linear issue attachments: upload (fileUpload → PUT → attachmentCreate) and authorized download.
 */
class LinearAttachmentService
{
    /** @var list<string> */
    private const ALLOWED_LINEAR_ASSET_HOSTS = [
        'uploads.linear.app',
        'public.linear.app',
    ];

    public function __construct(
        private readonly LinearApiClient $linearApiClient,
        private readonly ?HttpClientInterface $uploadClient = null,
        private readonly ?HttpClientInterface $assetHttpClient = null,
        private readonly ?LinearIssueMapper $issueMapper = null,
    ) {
    }

    /**
     * @return list<array{filename: string, contentUrl: string}>
     *
     * @throws ApiException When the issue cannot be loaded
     */
    public function fetchAttachmentsForIssue(string $issueKey): array
    {
        $workItem = $this->issueMapper()->mapToWorkItem(
            $this->linearApiClient->getIssue($issueKey),
            null,
        );

        $out = [];
        foreach ($workItem->attachments as $attachment) {
            $out[] = [
                'filename' => $attachment->filename,
                'contentUrl' => $attachment->contentUrl,
            ];
        }

        return $out;
    }

    /**
     * Downloads raw attachment bytes from an allowlisted Linear asset host using API key auth.
     *
     * @throws ApiException On HTTP errors or when the URL is not allowed
     */
    public function downloadAttachmentContent(string $contentUrl): string
    {
        $target = trim($contentUrl);
        if ($target === '') {
            throw new ApiException('Invalid attachment URL.', '', 400);
        }

        $this->assertAllowedLinearAssetHost($target);

        $response = $this->assetHttpClient()->request('GET', $target, [
            'headers' => [
                'Accept' => '*/*',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                'Attachment download failed.',
                $this->extractTechnicalDetails($response),
                $response->getStatusCode(),
            );
        }

        return $response->getContent();
    }

    public function uploadFileToIssue(string $issueKey, string $absolutePath): void
    {
        if ($absolutePath === '' || ! is_readable($absolutePath) || ! is_file($absolutePath)) {
            throw new ApiException('Cannot read local file for upload.', '', 400);
        }

        $filename = basename($absolutePath);

        $body = file_get_contents($absolutePath);
        // @codeCoverageIgnoreStart
        if ($body === false) {
            throw new ApiException('Cannot read local file for upload.', '', 400);
        }
        // @codeCoverageIgnoreEnd

        $upload = $this->linearApiClient->fileUpload(
            $filename,
            $this->detectContentType($absolutePath),
            strlen($body),
        );

        $putHeaders = [];
        foreach ($upload['headers'] as $header) {
            $putHeaders[$header['key']] = $header['value'];
        }

        $response = $this->uploadHttpClient()->request('PUT', $upload['uploadUrl'], [
            'headers' => $putHeaders,
            'body' => $body,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ApiException(
                'Linear attachment upload failed.',
                $this->extractTechnicalDetails($response),
                $statusCode,
            );
        }

        $this->linearApiClient->attachmentCreate($issueKey, $filename, $upload['assetUrl']);
    }

    /**
     * @throws ApiException When the URL host is not an allowed Linear asset host
     */
    private function assertAllowedLinearAssetHost(string $contentUrl): void
    {
        $parts = parse_url($contentUrl);
        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            throw new ApiException('Invalid attachment URL.', '', 400);
        }

        $host = (string) $parts['host'];
        foreach (self::ALLOWED_LINEAR_ASSET_HOSTS as $allowedHost) {
            if (strcasecmp($host, $allowedHost) === 0) {
                return;
            }
        }

        throw new ApiException(
            'Attachment URL host must be an allowed Linear asset host.',
            '',
            400,
        );
    }

    private function issueMapper(): LinearIssueMapper
    {
        return $this->issueMapper ?? new LinearIssueMapper();
    }

    private function detectContentType(string $absolutePath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = @finfo_file($finfo, $absolutePath);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return 'application/octet-stream';
    }

    private function uploadHttpClient(): HttpClientInterface
    {
        return $this->uploadClient ?? HttpClient::create();
    }

    private function assetHttpClient(): HttpClientInterface
    {
        return $this->assetHttpClient ?? HttpClient::create();
    }

    private function extractTechnicalDetails(ResponseInterface $response): string
    {
        try {
            return $response->getContent(false);
        } catch (\Throwable) {
            return 'HTTP ' . $response->getStatusCode();
        }
    }
}
