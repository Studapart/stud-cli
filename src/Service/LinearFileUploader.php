<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Uploads file bytes to Linear storage: fileUpload signing, then PUT to the signed URL.
 */
class LinearFileUploader
{
    /**
     * Linear fileUpload signs GCS PUT URLs with this content-type; PUT must match exactly.
     */
    private const LINEAR_UPLOAD_CONTENT_TYPE = 'application/x-www-form-urlencoded';

    public function __construct(
        private readonly LinearApiClient $linearApiClient,
        private readonly HttpClientInterface $uploadClient,
    ) {
    }

    /**
     * @return string Linear asset URL of the uploaded file
     *
     * @throws ApiException When signing fails or the PUT is not 2xx
     */
    public function upload(string $filename, string $body): string
    {
        $upload = $this->linearApiClient->fileUpload(
            $filename,
            self::LINEAR_UPLOAD_CONTENT_TYPE,
            strlen($body),
        );

        $putHeaders = [];
        foreach ($upload['headers'] as $header) {
            $putHeaders[$header['key']] = $header['value'];
        }
        $putHeaders['Content-Type'] = self::LINEAR_UPLOAD_CONTENT_TYPE;

        $response = $this->uploadClient->request('PUT', $upload['uploadUrl'], [
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

        return $upload['assetUrl'];
    }

    protected function extractTechnicalDetails(ResponseInterface $response): string
    {
        try {
            return $response->getContent(false);
        } catch (\Throwable) {
            return 'HTTP ' . $response->getStatusCode();
        }
    }
}
