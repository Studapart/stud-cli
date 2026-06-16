<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class JiraUserSearchService
{
    public function __construct(
        private readonly HttpClientInterface $client,
    ) {
    }

    /**
     * @return array{accountId: string, displayName: string}|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $query = trim($email);
        if ($query === '') {
            return null;
        }
        $response = $this->client->request('GET', '/rest/api/3/user/search', [
            'query' => ['query' => $query, 'maxResults' => 10],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $users = $response->toArray();
        $exact = $this->findExactEmailMatchInUsers($users, $query);
        if ($exact !== null) {
            return $exact;
        }
        if (str_contains($query, '@')) {
            $candidates = $this->collectUserCandidatesWithAt($users);
            if (count($candidates) === 1) {
                return $candidates[0];
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $users
     *
     * @return array{accountId: string, displayName: string}|null
     */
    public function findExactEmailMatchInUsers(array $users, string $query): ?array
    {
        foreach ($users as $user) {
            $accountId = $user['accountId'] ?? null;
            if (! is_string($accountId) || $accountId === '') {
                continue;
            }
            $matchEmail = $user['emailAddress'] ?? null;
            if (! is_string($matchEmail) || strcasecmp(trim($matchEmail), $query) !== 0) {
                continue;
            }
            $displayName = $user['displayName'] ?? '';
            $safeDisplayName = $matchEmail;
            if (is_string($displayName)) {
                $safeDisplayName = $displayName;
            }

            return [
                'accountId' => $accountId,
                'displayName' => $safeDisplayName,
            ];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $users
     *
     * @return array<int, array{accountId: string, displayName: string}>
     */
    public function collectUserCandidatesWithAt(array $users): array
    {
        $candidates = [];
        foreach ($users as $user) {
            $accountId = $user['accountId'] ?? null;
            if (! is_string($accountId) || $accountId === '') {
                continue;
            }
            $displayName = $user['displayName'] ?? '';
            $safeDisplayName = '';
            if (is_string($displayName)) {
                $safeDisplayName = $displayName;
            }

            $candidates[] = [
                'accountId' => $accountId,
                'displayName' => $safeDisplayName,
            ];
        }

        return $candidates;
    }
}
