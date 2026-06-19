<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ConfigRemediationService;
use App\Service\GitBranchService;
use App\Service\Logger;
use App\Service\TranslationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigRemediationServiceTest extends TestCase
{
    private ConfigRemediationService $service;
    private Logger&MockObject $logger;
    private TranslationService&MockObject $translator;
    private GitBranchService&MockObject $gitBranchService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $this->translator = $this->createMock(TranslationService::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->service = new ConfigRemediationService($this->logger, $this->translator, $this->gitBranchService);
    }

    public function testAutoDetectBaseBranch(): void
    {
        $this->gitBranchService->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['develop', 'main', 'feature/test']);

        $result = $this->service->autoDetectKey('baseBranch');

        $this->assertSame('develop', $result);
    }

    public function testAutoDetectBaseBranchReturnsNullWhenNotFound(): void
    {
        $this->gitBranchService->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['feature/test', 'bugfix/fix']);

        $result = $this->service->autoDetectKey('baseBranch');

        $this->assertNull($result);
    }

    public function testAutoDetectBaseBranchReturnsNullWhenGitBranchServiceIsNull(): void
    {
        $service = new ConfigRemediationService($this->logger, $this->translator, null);

        $result = $service->autoDetectKey('baseBranch');

        $this->assertNull($result);
    }

    public function testAutoDetectKeyWithUnknownKey(): void
    {
        $result = $this->service->autoDetectKey('unknown_key');

        $this->assertNull($result);
    }

    public function testPromptForMissingKeysWithAutoDetection(): void
    {
        $this->gitBranchService->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['develop']);

        $this->logger->expects($this->once())
            ->method('addNote');

        $result = $this->service->promptForMissingKeys(['baseBranch'], 'project');

        $this->assertSame('develop', $result['baseBranch']);
    }

    public function testPromptForMissingKeysWithoutAutoDetection(): void
    {
        $this->gitBranchService->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn([]);

        $this->logger->expects($this->once())
            ->method('ask')
            ->willReturn('custom-branch');

        $result = $this->service->promptForMissingKeys(['baseBranch'], 'project');

        $this->assertSame('custom-branch', $result['baseBranch']);
    }

    public function testPromptForMissingKeysWithEmptyValue(): void
    {
        $this->logger->expects($this->once())
            ->method('ask')
            ->willReturn('');

        $result = $this->service->promptForMissingKeys(['testKey'], 'global');

        $this->assertArrayNotHasKey('testKey', $result);
    }

    public function testAutoDetectBaseBranchHandlesException(): void
    {
        $this->gitBranchService->method('getAllRemoteBranches')
            ->with('origin')
            ->willThrowException(new \RuntimeException('Git error'));

        $result = $this->service->autoDetectKey('baseBranch');

        $this->assertNull($result);
    }
}
