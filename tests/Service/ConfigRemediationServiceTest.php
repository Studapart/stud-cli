<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\IssueTrackerProvider;
use App\Service\ConfigRemediationService;
use App\Service\GitBranchService;
use App\Service\Logger;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;

class ConfigRemediationServiceTest extends TestCase
{
    private ConfigRemediationService $service;
    private Logger $logger;
    private TranslationService&\PHPUnit\Framework\MockObject\Stub $translator;
    private GitBranchService $gitBranchService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createStub(Logger::class);
        $this->translator = $this->createStub(TranslationService::class);
        $this->gitBranchService = $this->createStub(GitBranchService::class);
        $this->bindSubject();
    }

    public function testAutoDetectBaseBranch(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $this->gitBranchService->expects($this->once())->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['develop', 'main', 'feature/test']);

        $result = $this->service->autoDetectKey('baseBranch');

        $this->assertSame('develop', $result);
    }

    public function testAutoDetectBaseBranchReturnsNullWhenNotFound(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $this->gitBranchService->expects($this->once())->method('getAllRemoteBranches')
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
        $this->logger = $this->createMock(Logger::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $this->gitBranchService->expects($this->once())->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['develop']);

        $this->logger->expects($this->once())
            ->method('addNote');

        $result = $this->service->promptForMissingKeys(['baseBranch'], 'project');

        $this->assertSame('develop', $result['baseBranch']);
    }

    public function testPromptForMissingKeysWithoutAutoDetection(): void
    {
        $this->logger = $this->createMock(Logger::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $this->gitBranchService->expects($this->once())->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn([]);

        $this->logger->expects($this->once())
            ->method('addNote');
        $this->logger->expects($this->once())
            ->method('ask')
            ->willReturn('custom-branch');

        $result = $this->service->promptForMissingKeys(['baseBranch'], 'project');

        $this->assertSame('custom-branch', $result['baseBranch']);
    }

    public function testPromptForIssueTrackerProviderUsesChoice(): void
    {
        $this->logger = $this->createMock(Logger::class);
        $this->bindSubject();

        $prompt = $this->createMock(\App\Service\Prompt\PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->willReturn(IssueTrackerProvider::Jira->value);
        $this->logger->expects($this->exactly(2))->method('addNote');

        $service = new ConfigRemediationService($this->logger, $this->translator, null, $prompt);
        $result = $service->promptForMissingKeys(['issueTrackerProvider'], 'project');

        $this->assertSame(IssueTrackerProvider::Jira->value, $result['issueTrackerProvider']);
    }

    public function testPromptForIssueTrackerProviderWithoutChoiceUsesAsk(): void
    {
        $this->logger = $this->createMock(Logger::class);
        $this->bindSubject();

        $this->logger->expects($this->once())
            ->method('ask')
            ->willReturn(IssueTrackerProvider::Linear->value);

        $result = $this->service->promptForMissingKeys(['issueTrackerProvider'], 'project');

        $this->assertSame(IssueTrackerProvider::Linear->value, $result['issueTrackerProvider']);
    }

    public function testPromptForMissingKeysWithEmptyValue(): void
    {
        $this->logger = $this->createMock(Logger::class);
        $this->bindSubject();

        $this->logger->expects($this->once())
            ->method('ask')
            ->willReturn('');

        $result = $this->service->promptForMissingKeys(['testKey'], 'global');

        $this->assertArrayNotHasKey('testKey', $result);
    }

    public function testAutoDetectBaseBranchHandlesException(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->bindSubject();

        $this->gitBranchService->expects($this->once())->method('getAllRemoteBranches')
            ->with('origin')
            ->willThrowException(new \RuntimeException('Git error'));

        $result = $this->service->autoDetectKey('baseBranch');

        $this->assertNull($result);
    }

    private function bindSubject(): void
    {
        $this->service = new ConfigRemediationService($this->logger, $this->translator, $this->gitBranchService);
    }
}
