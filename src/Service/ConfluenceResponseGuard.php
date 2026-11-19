<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ConfluenceResponseGuard
{
    /**
     * @throws ApiException when the response status is not one of the expected statuses
     */
    public function assertStatus(ResponseInterface $response, string $errorMessage, int ...$expectedStatuses): void
    {
        $statusCode = $response->getStatusCode();
        if (in_array($statusCode, $expectedStatuses, true)) {
            return;
        }

        throw new ApiException($errorMessage, $this->extractTechnicalDetails($response), $statusCode);
    }

    protected function extractTechnicalDetails(ResponseInterface $response): string
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
        } catch (\Exception $e) {
            $responseBody = 'Unable to read response body: ' . $e->getMessage();
        }

        return "HTTP {$statusCode}. {$responseBody}";
    }
}
