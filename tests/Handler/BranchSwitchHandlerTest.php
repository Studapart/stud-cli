<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\BranchSwitchHandler;
use App\Tests\CommandTestCase;

class BranchSwitchHandlerTest extends CommandTestCase
{
    private BranchSwitchHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new BranchSwitchHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $this->translationService
        );
    }

    public function testHandleFailsForBlankKey(): void
    {
        $this->gitRepository->expects($this->never())
            ->method('getPorcelainStatus');

        $response = $this->handler->handle(' ');

        $this->assertFalse($response->isSuccess());
        $this->assertMessageRef($response->getErrorMessage(), 'branch.switch.error_no_key');
    }

    public function testHandleFailsWhenWorkingDirectoryIsDirty(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn(" M file.php\n");
        $this->gitBranchService->expects($this->never())
            ->method('findLocalBranchesContainingIssueKey');

        $response = $this->handler->handle('SCI-123');

        $this->assertFalse($response->isSuccess());
        $this->assertMessageRef($response->getErrorMessage(), 'branch.switch.error_dirty_working');
    }

    public function testHandleFailsWhenNoLocalBranchMatches(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitBranchService->expects($this->once())
            ->method('findLocalBranchesContainingIssueKey')
            ->with('SCI-123')
            ->willReturn([]);

        $response = $this->handler->handle('sci-123');

        $this->assertFalse($response->isSuccess());
        $this->assertMessageRef(
            $response->getErrorMessage(),
            'branch.switch.error_no_branch',
            ['key' => 'SCI-123']
        );
    }

    public function testHandleSwitchesWhenOneBranchMatches(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitBranchService->method('findLocalBranchesContainingIssueKey')
            ->willReturn(['feat/SCI-123-title']);
        $this->gitBranchService->expects($this->once())
            ->method('switchBranch');

        $response = $this->handler->handle('SCI-123');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('feat/SCI-123-title', $response->branch);
    }

    public function testHandleSwitchesToLinearIdentifierBranchProviderAgnostic(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitBranchService->expects($this->once())
            ->method('findLocalBranchesContainingIssueKey')
            ->with('SCI-123')
            ->willReturn(['feat/SCI-123-foo']);

        $response = $this->handler->handle('SCI-123');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('SCI-123', $response->key);
        $this->assertSame('feat/SCI-123-foo', $response->branch);
    }

    public function testHandleFailsWithBranchesInQuietModeWhenMultipleBranchesMatch(): void
    {
        $matches = ['feat/SCI-123-a', 'fix/SCI-123-b'];
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitBranchService->method('findLocalBranchesContainingIssueKey')
            ->willReturn($matches);
        $this->gitBranchService->expects($this->never())
            ->method('switchBranch');

        $response = $this->handler->handle('SCI-123', true);

        $this->assertFalse($response->isSuccess());
        $this->assertSame($matches, $response->matches);
        $this->assertMessageRef(
            $response->getErrorMessage(),
            'branch.switch.error_multiple_branches',
            [
                'key' => 'SCI-123',
                'branches' => "- feat/SCI-123-a\n- fix/SCI-123-b",
            ]
        );
    }

    public function testHandleNeedsSelectionInInteractiveModeWhenMultipleBranchesMatch(): void
    {
        $matches = ['feat/SCI-123-a', 'fix/SCI-123-b'];
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitBranchService->method('findLocalBranchesContainingIssueKey')
            ->willReturn($matches);

        $response = $this->handler->handle('SCI-123');

        $this->assertFalse($response->isSuccess());
        $this->assertTrue($response->needsSelection);
        $this->assertSame($matches, $response->matches);
    }

    public function testHandleSwitchesSelectedBranch(): void
    {
        $matches = ['feat/SCI-123-a', 'fix/SCI-123-b'];
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitBranchService->method('findLocalBranchesContainingIssueKey')
            ->willReturn($matches);
        $this->gitBranchService->expects($this->once())
            ->method('switchBranch');

        $response = $this->handler->handle('SCI-123', false, 'fix/SCI-123-b');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('fix/SCI-123-b', $response->branch);
    }

    public function testHandleReturnsErrorWhenSwitchFails(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitBranchService->method('findLocalBranchesContainingIssueKey')
            ->willReturn(['feat/SCI-123-title']);
        $this->gitBranchService->method('switchBranch')
            ->willThrowException(new \RuntimeException('git switch failed'));

        $response = $this->handler->handle('SCI-123');

        $this->assertFalse($response->isSuccess());
        $this->assertIsString($response->getErrorMessage());
        $this->assertSame('git switch failed', $response->getErrorMessage());
    }
}
