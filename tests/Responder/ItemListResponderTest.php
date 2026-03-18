<?php

namespace App\Tests\Responder;

use App\DTO\WorkItem;
use App\Enum\OutputFormat;
use App\Responder\ItemListResponder;
use App\Response\ItemListResponse;
use App\Service\ColorHelper;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemListResponderTest extends CommandTestCase
{
    private SymfonyStyle&\PHPUnit\Framework\MockObject\MockObject $io;

    private ItemListResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $helper = new ResponderHelper($this->translationService, null);
        $this->io = $this->createMock(SymfonyStyle::class);
        $this->responder = new ItemListResponder($helper, $this->createLogger($this->io));
    }

    public function testRespondReturnsZeroOnEmptyIssues(): void
    {
        $response = ItemListResponse::success([], false, null);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemListResponder(new ResponderHelper($this->translationService, null), $this->createLogger($io));

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('note')
            ->with($this->anything());

        $responder->respond($io, $response);
    }

    public function testRespondRendersTableOnSuccess(): void
    {
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = ItemListResponse::success([$issue], false, null);

        $this->io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $this->io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($this->io, $response);
    }

    public function testRespondShowsVerboseOutputWhenVerbose(): void
    {
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = ItemListResponse::success([$issue], false, null);
        $io = $this->createSymfonyStyle(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);
        $responder = new ItemListResponder(new ResponderHelper($this->translationService, null), new \App\Service\Logger($io, []));

        $responder->respond($io, $response);

        $output = $this->getOutput($io);
        $this->assertStringContainsString('item.list.section', $output);
        $this->assertStringContainsString('JQL Query', $output);
    }

    public function testRespondShowsVerboseOutputWithoutColorHelperUsesFallback(): void
    {
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = ItemListResponse::success([$issue], false, null);
        $io = $this->createSymfonyStyle(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);
        $responder = new ItemListResponder(new ResponderHelper($this->translationService, null), new \App\Service\Logger($io, []));

        $responder->respond($io, $response);

        $output = $this->getOutput($io);
        $this->assertStringContainsString('JQL Query', $output);
    }

    public function testRespondShowsVerboseOutputWithColorHelper(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $colorHelper->expects($this->atLeastOnce())
            ->method('registerStyles')
            ->with($this->anything());
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->willReturnCallback(function ($color, $text) {
                return "<{$color}>{$text}</>";
            });

        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $io = $this->createSymfonyStyle(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);
        $responder = new ItemListResponder($helper, new \App\Service\Logger($io, []));

        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = ItemListResponse::success([$issue], false, null);

        $responder->respond($io, $response);

        $output = $this->getOutput($io);
        $this->assertStringContainsString('item.list.section', $output);
        $this->assertStringContainsString('JQL Query', $output);
    }

    public function testRespondShowsVerboseOutputWithProject(): void
    {
        $io = $this->createSymfonyStyle(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);
        $responder = new ItemListResponder(new ResponderHelper($this->translationService, null), new \App\Service\Logger($io, []));
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = ItemListResponse::success([$issue], false, 'MYPROJ');

        $responder->respond($io, $response);

        $output = $this->getOutput($io);
        $this->assertStringContainsString('MYPROJ', $output);
    }

    public function testRespondWithColorHelperRegistersStyles(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemListResponder($helper, $this->createLogger($io));
        $response = ItemListResponse::success([], false, null);

        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($io);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->once())
            ->method('note');

        $responder->respond($io, $response);
    }

    public function testRespondJsonReturnsSerializedIssues(): void
    {
        $issue = new WorkItem('1', 'PROJ-1', 'Test', 'Open', 'user', '', [], 'Story');
        $response = ItemListResponse::success([$issue], false, null);

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data['issues']);
        $this->assertFalse($result->data['all']);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = ItemListResponse::error('API error');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertSame('API error', $result->error);
    }
}
