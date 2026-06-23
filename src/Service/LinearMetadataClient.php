<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Read-only Linear GraphQL client for project metadata discovery (workflow states, labels).
 */
class LinearMetadataClient
{
    private const TEAM_STATES_QUERY = <<<'GQL'
        query TeamStates($teamKey: String!) {
          team(id: $teamKey) {
            states {
              nodes {
                id
                name
                type
              }
            }
          }
        }
        GQL;

    public function __construct(
        private readonly HttpClientInterface $client,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, type: string}>
     */
    public function getTeamWorkflowStates(string $teamKey): array
    {
        $response = $this->client->request('POST', 'graphql', [
            'json' => [
                'query' => self::TEAM_STATES_QUERY,
                'variables' => ['teamKey' => $teamKey],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Could not fetch Linear workflow states for team \"{$teamKey}\".",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();
        $this->assertNoGraphQlErrors($data, $teamKey);

        $nodes = $data['data']['team']['states']['nodes'] ?? [];
        if (! is_array($nodes)) {
            return [];
        }

        $states = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['id'], $node['name'], $node['type'])) {
                continue;
            }
            $states[] = [
                'id' => (string) $node['id'],
                'name' => (string) $node['name'],
                'type' => (string) $node['type'],
            ];
        }

        return $states;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function assertNoGraphQlErrors(array $data, string $teamKey): void
    {
        if (! isset($data['errors']) || ! is_array($data['errors']) || $data['errors'] === []) {
            return;
        }

        $first = $data['errors'][0] ?? [];
        $message = is_array($first) && isset($first['message']) && is_string($first['message'])
            ? $first['message']
            : 'Linear GraphQL request failed.';

        throw new ApiException(
            "Could not fetch Linear workflow states for team \"{$teamKey}\".",
            $message,
        );
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
