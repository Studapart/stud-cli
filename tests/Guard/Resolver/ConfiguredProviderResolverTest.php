<?php

declare(strict_types=1);

namespace App\Tests\Guard\Resolver;

use App\Enum\WorkItemCommandProfile;
use App\Exception\IssueTrackerResolutionException;
use App\Guard\DTO\ProviderResolutionRequest;
use App\Guard\Resolver\ConfiguredProviderResolver;
use App\Guard\Resolver\IssueTrackerResolutionDeps;
use App\Service\IssueTrackerFactory;
use App\Service\IssueTrackerResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfiguredProviderResolverTest extends TestCase
{
    private const DUAL_GLOBAL_CONFIG = [
        'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
        'JIRA_URL' => 'https://example.atlassian.net',
        'JIRA_EMAIL' => 'user@example.com',
        'JIRA_API_TOKEN' => 'token',
        'LINEAR_API_KEY' => 'lin',
    ];

    private const DUAL_PROJECT_CONFIG = [
        'issueTrackerProvider' => 'auto',
        'projectKey' => 'SCI',
        'linearTeamKey' => 'SCIL',
    ];

    private const JIRA_GLOBAL_CONFIG = [
        'ISSUE_TRACKER_PROVIDERS' => ['jira'],
        'JIRA_URL' => 'https://x.test',
        'JIRA_EMAIL' => 'a@b.c',
        'JIRA_API_TOKEN' => 't',
    ];

    private const JIRA_PROJECT_CONFIG = ['issueTrackerProvider' => 'jira'];

    /**
     * @return iterable<string, array{WorkItemCommandProfile}>
     */
    public static function dualProfiles(): iterable
    {
        yield 'dual aggregate' => [WorkItemCommandProfile::DualAggregate];
        yield 'filter by name' => [WorkItemCommandProfile::FilterByName];
    }

    #[DataProvider('dualProfiles')]
    public function testDualAutoProfileReturnsDualDefault(WorkItemCommandProfile $profile): void
    {
        $result = (new ConfiguredProviderResolver())->resolve(
            $this->request($profile, self::DUAL_GLOBAL_CONFIG),
            self::DUAL_PROJECT_CONFIG,
        );

        $this->assertSame(['jira', 'linear'], $result->providers);
        $this->assertSame('dual_default', $result->inferredFrom);
    }

    public function testActiveProjectProviderIsUsed(): void
    {
        $result = (new ConfiguredProviderResolver())->resolve(
            $this->request(WorkItemCommandProfile::KeySingle, self::JIRA_GLOBAL_CONFIG),
            self::JIRA_PROJECT_CONFIG,
        );

        $this->assertSame(['jira'], $result->providers);
        $this->assertSame('project_config', $result->inferredFrom);
    }

    public function testFallsBackToGlobalSingleProvider(): void
    {
        $result = $this->resolverWhoseFallbackYields(static fn (): string => 'jira')->resolve(
            $this->request(WorkItemCommandProfile::DualAggregate, self::JIRA_GLOBAL_CONFIG),
            self::JIRA_PROJECT_CONFIG,
        );

        $this->assertFalse($result->isBlocked());
        $this->assertSame(['jira'], $result->providers);
        $this->assertSame('global_single', $result->inferredFrom);
    }

    public function testResolutionExceptionBlocksWithItsMessage(): void
    {
        $result = $this->resolverWhoseFallbackYields(static function (): never {
            throw IssueTrackerResolutionException::unknownPrefix('OPS', 'SCI (jira)');
        })->resolve(
            $this->request(WorkItemCommandProfile::DualAggregate, self::JIRA_GLOBAL_CONFIG),
            self::JIRA_PROJECT_CONFIG,
        );

        $this->assertTrue($result->isBlocked());
        $this->assertSame('issue_tracker_provider.unknown_prefix', $result->block?->key);
    }

    public function testUnexpectedThrowableBlocksAsAmbiguous(): void
    {
        $result = $this->resolverWhoseFallbackYields(static function (): never {
            throw new \RuntimeException('boom');
        })->resolve(
            $this->request(WorkItemCommandProfile::DualAggregate, self::JIRA_GLOBAL_CONFIG),
            self::JIRA_PROJECT_CONFIG,
        );

        $this->assertTrue($result->isBlocked());
        $this->assertSame('guard.error.ambiguous_issue_tracker_provider', $result->block?->key);
    }

    /**
     * The first resolveType call (active provider lookup) fails; the second runs $fallback.
     *
     * @param \Closure(): string $fallback
     */
    private function resolverWhoseFallbackYields(\Closure $fallback): ConfiguredProviderResolver
    {
        $calls = 0;
        $factory = $this->createStub(IssueTrackerFactory::class);
        $factory->method('resolveType')->willReturnCallback(static function () use (&$calls, $fallback): string {
            if (++$calls === 1) {
                throw IssueTrackerResolutionException::invalidOverride('auto');
            }

            return $fallback();
        });

        return new ConfiguredProviderResolver(
            new IssueTrackerResolutionDeps(factory: $factory, resolver: new IssueTrackerResolver(factory: $factory)),
        );
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    private function request(WorkItemCommandProfile $profile, array $globalConfig): ProviderResolutionRequest
    {
        return new ProviderResolutionRequest(
            commandName: 'filters:list',
            commandProfile: $profile,
            globalConfig: $globalConfig,
            projectConfig: null,
        );
    }
}
