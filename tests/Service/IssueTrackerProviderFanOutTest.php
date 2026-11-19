<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\DTO\ProviderFanOutResult;
use App\DTO\ResponseMessage;
use App\Service\IssueTrackerPort;
use App\Service\IssueTrackerPortSupplier;
use App\Service\IssueTrackerProviderFanOut;
use App\Service\IssueTrackerProvidersQueryResolver;
use PHPUnit\Framework\TestCase;

final class IssueTrackerProviderFanOutTest extends TestCase
{
    private const array DUAL_GLOBAL = [
        'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
        'JIRA_URL' => 'https://example.atlassian.net',
        'JIRA_EMAIL' => 'user@example.com',
        'JIRA_API_TOKEN' => 'token',
        'LINEAR_API_KEY' => 'lin',
    ];

    private const array DUAL_PROJECT = [
        'issueTrackerProvider' => 'auto',
        'projectKey' => 'SCI',
        'linearTeamKey' => 'ENG',
    ];

    public function testCollectsItemsFromOverriddenProvider(): void
    {
        $port = $this->createStub(IssueTrackerPort::class);
        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->expects($this->never())->method('resolve');
        $supplier->expects($this->once())
            ->method('resolveForProvider')
            ->with('linear', [], [])
            ->willReturn(['ok' => true, 'provider' => 'linear', 'port' => $port]);

        $result = $this->fanOut($supplier, [], [])->collect('linear', fn (IssueTrackerPort $p): array => ['a', 'b'], 'fetch.error');

        $this->assertInstanceOf(ProviderFanOutResult::class, $result);
        $this->assertSame(['a', 'b'], $result->items);
        $this->assertSame(['linear', 'linear'], $result->providers);
        $this->assertSame([], $result->warnings);
    }

    public function testFallsBackToActiveProviderWhenNoProviderIsQueried(): void
    {
        $port = $this->createStub(IssueTrackerPort::class);
        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->expects($this->once())
            ->method('resolve')
            ->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $port]);
        $supplier->expects($this->once())
            ->method('resolveForProvider')
            ->with('jira')
            ->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $port]);

        $result = $this->fanOut($supplier, [], [])->collect(null, fn (IssueTrackerPort $p): array => ['a'], 'fetch.error');

        $this->assertInstanceOf(ProviderFanOutResult::class, $result);
        $this->assertSame(['jira'], $result->providers);
    }

    public function testReturnsFallbackResolutionError(): void
    {
        $error = MessageRef::key('issue_tracker_provider.not_configured');
        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolve')->willReturn(['ok' => false, 'error' => $error]);
        $supplier->expects($this->never())->method('resolveForProvider');

        $result = $this->fanOut($supplier, [], [])->collect(null, fn (IssueTrackerPort $p): array => [], 'fetch.error');

        $this->assertSame($error, $result);
    }

    public function testReturnsResolutionErrorForSingleProvider(): void
    {
        $error = MessageRef::key('issue_tracker_provider.invalid_override');
        $supplier = $this->createStub(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturn(['ok' => false, 'error' => $error]);

        $result = $this->fanOut($supplier, [], [])->collect('jira', fn (IssueTrackerPort $p): array => [], 'fetch.error');

        $this->assertSame($error, $result);
    }

    public function testReturnsFetchErrorForSingleProvider(): void
    {
        $supplier = $this->createStub(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')
            ->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->createStub(IssueTrackerPort::class)]);

        $result = $this->fanOut($supplier, [], [])->collect(
            'jira',
            fn (IssueTrackerPort $p): array => throw new \RuntimeException('boom'),
            'fetch.error',
            false,
        );

        $this->assertInstanceOf(MessageRef::class, $result);
        $this->assertSame('fetch.error', $result->key);
        $this->assertSame(['error' => 'boom'], $result->parameters);
    }

    public function testReturnsFetchErrorWhenPortThrowsError(): void
    {
        $supplier = $this->createStub(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')
            ->willReturn(['ok' => true, 'provider' => 'jira', 'port' => $this->createStub(IssueTrackerPort::class)]);

        $result = $this->fanOut($supplier, [], [])->collect(
            'jira',
            fn (IssueTrackerPort $p): array => throw new \Error('type blew up'),
            'fetch.error',
        );

        $this->assertInstanceOf(MessageRef::class, $result);
        $this->assertSame('fetch.error', $result->key);
        $this->assertSame(['error' => 'type blew up'], $result->parameters);
    }

    public function testSkipsFailedProvidersWithWarningsWhenSeveralAreQueried(): void
    {
        $resolutionError = MessageRef::key('issue_tracker_provider.invalid_override');
        $supplier = $this->dualSupplier(['ok' => false, 'error' => $resolutionError]);

        $result = $this->fanOut($supplier, self::DUAL_GLOBAL, self::DUAL_PROJECT)->collect(
            null,
            fn (IssueTrackerPort $p): array => throw new \RuntimeException('boom'),
            'fetch.error',
        );

        $this->assertInstanceOf(ProviderFanOutResult::class, $result);
        $this->assertSame([], $result->items);
        $this->assertCount(2, $result->warnings);
        $this->assertWarningKey('fetch.error', $result->warnings[0]);
        $this->assertSame($resolutionError, $result->warnings[1]->message);
    }

    public function testSkipsFetchFailuresSilentlyWhenWarningsAreDisabled(): void
    {
        $resolutionError = MessageRef::key('issue_tracker_provider.invalid_override');
        $supplier = $this->dualSupplier(['ok' => false, 'error' => $resolutionError]);

        $result = $this->fanOut($supplier, self::DUAL_GLOBAL, self::DUAL_PROJECT)->collect(
            null,
            fn (IssueTrackerPort $p): array => throw new \RuntimeException('boom'),
            'fetch.error',
            false,
        );

        $this->assertInstanceOf(ProviderFanOutResult::class, $result);
        $this->assertCount(1, $result->warnings);
        $this->assertSame($resolutionError, $result->warnings[0]->message);
        $this->assertCount(1, $result->fetchFailures);
        $this->assertSame('fetch.error', $result->fetchFailures[0]->key);
        $this->assertSame(['error' => 'boom'], $result->fetchFailures[0]->parameters);
    }

    public function testCollectsItemsFromEveryProviderInOrder(): void
    {
        $jiraPort = $this->createStub(IssueTrackerPort::class);
        $linearPort = $this->createStub(IssueTrackerPort::class);
        $supplier = $this->createStub(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static fn (string $provider): array => [
                'ok' => true,
                'provider' => $provider === 'jira' ? 'jira' : 'linear',
                'port' => $provider === 'jira' ? $jiraPort : $linearPort,
            ],
        );

        $result = $this->fanOut($supplier, self::DUAL_GLOBAL, self::DUAL_PROJECT)->collect(
            null,
            fn (IssueTrackerPort $p): array => $p === $jiraPort ? ['j1', 'j2'] : ['l1'],
            'fetch.error',
        );

        $this->assertInstanceOf(ProviderFanOutResult::class, $result);
        $this->assertSame(['j1', 'j2', 'l1'], $result->items);
        $this->assertSame(['jira', 'jira', 'linear'], $result->providers);
        $this->assertTrue($result->isMultiProvider());
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    private function fanOut(IssueTrackerPortSupplier $supplier, array $globalConfig, array $projectConfig): IssueTrackerProviderFanOut
    {
        return new IssueTrackerProviderFanOut($supplier, $globalConfig, $projectConfig, new IssueTrackerProvidersQueryResolver());
    }

    /**
     * Jira resolves to a working port; Linear returns the given resolution.
     *
     * @param array{ok: false, error: MessageRef} $linearResolution
     */
    private function dualSupplier(array $linearResolution): IssueTrackerPortSupplier
    {
        $jiraPort = $this->createStub(IssueTrackerPort::class);
        $supplier = $this->createStub(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForProvider')->willReturnCallback(
            static fn (string $provider): array => $provider === 'linear'
                ? $linearResolution
                : ['ok' => true, 'provider' => 'jira', 'port' => $jiraPort],
        );

        return $supplier;
    }

    private function assertWarningKey(string $key, ResponseMessage $warning): void
    {
        $this->assertInstanceOf(MessageRef::class, $warning->message);
        $this->assertSame($key, $warning->message->key);
    }
}
