<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Uploads local files to Linear issues via fileUpload → signed PUT → attachmentCreate.
 */
class LinearAttachmentService
{
    public function __construct(
        private readonly LinearApiClient $linearApiClient,
        private readonly ?HttpClientInterface $uploadClient = null,
    ) {
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

    private function extractTechnicalDetails(ResponseInterface $response): string
    {
        try {
            return $response->getContent(false);
        } catch (\Throwable) {
            return 'HTTP ' . $response->getStatusCode();
        }
    }
}
