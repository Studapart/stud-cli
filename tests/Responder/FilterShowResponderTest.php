<?php

namespace App\Tests\Responder;

use App\DTO\WorkItem;
use App\Enum\OutputFormat;
use App\Responder\FilterShowResponder;
use App\Response\FilterShowResponse;
use App\Service\ColorHelper;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterShowResponderTest extends CommandTestCase
{
    private SymfonyStyle&\PHPUnit\Framework\MockObject\MockObject $io;

    private FilterShowResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $helper = new ResponderHelper($this->translationService, null);
        $this->io = $this->createMock(SymfonyStyle::class);
        $this->responder = new FilterShowResponder($helper, [
            'JIRA_URL' => 'https://jira.example.com',
        ], $this->createLogger($this->io));
    }

    public function testRespondReturnsZeroOnEmptyIssues(): void
    {
        $response = FilterShowResponse::success([], 'My Filter');

        $this->io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $this->io->expects($this->once())
            ->method('note')
            ->with($this->callback(function ($message) {
                return is_string($message) && str_contains($message, 'My Filter');
            }));

        $this->responder->respond($this->io, $response);
    }

    public function testRespondRendersTableOnSuccess(): void
    {
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = FilterShowResponse::success([$issue], 'My Filter');

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
        $response = FilterShowResponse::success([$issue], 'My Filter');
        $io = $this->createSymfonyStyle(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);
        $responder = new FilterShowResponder(new ResponderHelper($this->translationService, null), [
            'JIRA_URL' => 'https://jira.example.com',
        ], new \App\Service\Logger($io, []));

        $responder->respond($io, $response);

        $output = $this->getOutput($io);
        $this->assertStringContainsString('filter.show.section', $output);
        $this->assertStringContainsString('filter.show.jql_query', $output);
    }

    public function testRespondShowsVerboseOutputWithoutColorHelperUsesFallback(): void
    {
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = FilterShowResponse::success([$issue], 'My Filter');
        $io = $this->createSymfonyStyle(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);
        $responder = new FilterShowResponder(new ResponderHelper($this->translationService, null), [
            'JIRA_URL' => 'https://jira.example.com',
        ], new \App\Service\Logger($io, []));

        $responder->respond($io, $response);

        $output = $this->getOutput($io);
        $this->assertStringContainsString('filter.show.jql_query', $output);
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
        $responder = new FilterShowResponder($helper, [
            'JIRA_URL' => 'https://jira.example.com',
        ], new \App\Service\Logger($io, []));

        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = FilterShowResponse::success([$issue], 'My Filter');

        $responder->respond($io, $response);

        $output = $this->getOutput($io);
        $this->assertStringContainsString('filter.show.section', $output);
        $this->assertStringContainsString('filter.show.jql_query', $output);
    }

    public function testRespondWithColorHelperRegistersStyles(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new FilterShowResponder($helper, [
            'JIRA_URL' => 'https://jira.example.com',
        ], $this->createLogger($io));
        $response = FilterShowResponse::success([], 'My Filter');

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
        $issue = new WorkItem('1', 'PROJ-1', 'Test Issue', 'Open', 'user', '', [], 'Story');
        $response = FilterShowResponse::success([$issue], 'My Filter');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame('My Filter', $result->data['filterName']);
        $this->assertCount(1, $result->data['issues']);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = FilterShowResponse::error('API error');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertSame('API error', $result->error);
    }
}
