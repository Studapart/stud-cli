<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\JiraUserSearchService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class JiraUserSearchServiceTest extends TestCase
{
    private JiraUserSearchService $userSearch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userSearch = new JiraUserSearchService($this->createStub(HttpClientInterface::class));
    }

    public function testFindExactEmailMatchInUsersSkipsUserWithInvalidAccountId(): void
    {
        $users = [
            [
                'accountId' => 123,
                'emailAddress' => 'skip@example.com',
            ],
            [
                'accountId' => 'acc-2',
                'emailAddress' => 'match@example.com',
                'displayName' => 'Match',
            ],
        ];
        $result = $this->userSearch->findExactEmailMatchInUsers($users, 'match@example.com');

        $this->assertIsArray($result);
        $this->assertSame('acc-2', $result['accountId']);
        $this->assertSame('Match', $result['displayName']);
    }

    public function testFindExactEmailMatchInUsersUsesMatchEmailWhenDisplayNameNotString(): void
    {
        $users = [
            [
                'accountId' => 'acc-1',
                'emailAddress' => 'exact@example.com',
                'displayName' => 12345,
            ],
        ];
        $result = $this->userSearch->findExactEmailMatchInUsers($users, 'exact@example.com');

        $this->assertIsArray($result);
        $this->assertSame('acc-1', $result['accountId']);
        $this->assertSame('exact@example.com', $result['displayName']);
    }

    public function testCollectUserCandidatesWithAtSkipsUserWithInvalidAccountId(): void
    {
        $users = [
            [
                'accountId' => null,
                'displayName' => 'Skip',
            ],
            [
                'accountId' => 'valid',
                'displayName' => 'Valid',
            ],
        ];
        $result = $this->userSearch->collectUserCandidatesWithAt($users);

        $this->assertCount(1, $result);
        $this->assertSame('valid', $result[0]['accountId']);
        $this->assertSame('Valid', $result[0]['displayName']);
    }

    public function testCollectUserCandidatesWithAtUsesEmptyStringWhenDisplayNameNotString(): void
    {
        $users = [
            [
                'accountId' => 'acc-1',
                'displayName' => new \stdClass(),
            ],
        ];
        $result = $this->userSearch->collectUserCandidatesWithAt($users);

        $this->assertCount(1, $result);
        $this->assertSame('acc-1', $result[0]['accountId']);
        $this->assertSame('', $result[0]['displayName']);
    }
}
