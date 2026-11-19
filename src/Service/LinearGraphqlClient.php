<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Low-level Linear GraphQL HTTP client (SCI-165).
 */
final class LinearGraphqlClient
{
    private readonly LinearGraphqlResponseParser $responseParser;

    public function __construct(
        private readonly HttpClientInterface $client,
    ) {
        $this->responseParser = new LinearGraphqlResponseParser();
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed> GraphQL `data` node only
     *
     * @throws ApiException on HTTP != 2xx, GraphQL errors[], or missing data
     */
    public function query(string $query, array $variables = []): array
    {
        $response = $this->client->request('POST', '', [
            'json' => [
                'query' => $query,
                'variables' => $variables,
            ],
        ]);

        return $this->responseParser->parse($response);
    }
}
