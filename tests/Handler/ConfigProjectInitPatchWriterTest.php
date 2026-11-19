<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Config\SecretKeyPolicy;
use App\DTO\MessageRef;
use App\Handler\ConfigProjectInitPatchWriter;
use App\Service\GitRepository;
use App\Service\GitSetupService;
use PHPUnit\Framework\TestCase;

class ConfigProjectInitPatchWriterTest extends TestCase
{
    public function testEmptyPatchesReturnRedactedExistingConfigWithoutWriting(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn(['githubToken' => 'secret', 'projectKey' => 'SCI']);
        $gitRepository->expects($this->never())->method('writeProjectConfig');

        $response = (new ConfigProjectInitPatchWriter($gitRepository, $this->createStub(GitSetupService::class)))
            ->write([], false);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->updated);
        $this->assertSame(SecretKeyPolicy::REDACTED_PLACEHOLDER, $response->redactedProjectConfig['githubToken']);
        $this->assertSame('SCI', $response->redactedProjectConfig['projectKey']);
    }

    public function testMergesPatchesAfterRemoteBaseBranchCheck(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturnOnConsecutiveCalls(
            ['projectKey' => 'OLD', 'keep' => 'x'],
            ['projectKey' => 'SCI', 'keep' => 'x', 'baseBranch' => 'main'],
        );
        $gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'SCI', 'keep' => 'x', 'baseBranch' => 'main']);
        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->expects($this->once())->method('validateBaseBranchOnRemote')->with('main');

        $response = (new ConfigProjectInitPatchWriter($gitRepository, $gitSetup))
            ->write(['projectKey' => 'SCI', 'baseBranch' => 'main'], false);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->updated);
        $this->assertSame(['projectKey' => 'SCI', 'keep' => 'x', 'baseBranch' => 'main'], $response->redactedProjectConfig);
    }

    public function testSkipsRemoteCheckWhenRequested(): void
    {
        $gitRepository = $this->createStub(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->expects($this->never())->method('validateBaseBranchOnRemote');

        $response = (new ConfigProjectInitPatchWriter($gitRepository, $gitSetup))
            ->write(['baseBranch' => 'main'], true);

        $this->assertTrue($response->updated);
    }

    public function testRemoteBaseBranchFailureReturnsErrorWithoutWriting(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->expects($this->never())->method('writeProjectConfig');
        $gitSetup = $this->createStub(GitSetupService::class);
        $gitSetup->method('validateBaseBranchOnRemote')->willThrowException(new \RuntimeException('missing'));

        $response = (new ConfigProjectInitPatchWriter($gitRepository, $gitSetup))
            ->write(['baseBranch' => 'nope'], false);

        $this->assertFalse($response->isSuccess());
        $message = $response->getErrorMessage();
        $this->assertInstanceOf(MessageRef::class, $message);
        $this->assertSame('config.project_init.base_branch_error', $message->key);
        $this->assertSame(['%message%' => 'missing'], $message->parameters);
    }
}
