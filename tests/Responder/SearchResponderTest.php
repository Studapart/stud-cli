<?php

namespace App\Tests\Responder;

use App\DTO\WorkItem;
use App\Enum\OutputFormat;
use App\Responder\SearchResponder;
use App\Response\SearchResponse;
use App\Service\ColorHelper;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SearchResponderTest extends CommandTestCase
{
    private SymfonyStyle&\PHPUnit\Framework\MockObject\MockObject $io;

    private SearchResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $helper = new ResponderHelper($this->translationService, null);
        $this->io = $this->createMock(SymfonyStyle::class);
        $this->responder = new SearchResponder($helper, [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ], $this->createLogger($this->io));
    }

    public function testRespondReturnsZeroOnEmptyIssues(): void
    {
        $response = SearchResponse::success([], 'project = TPW');

        $this->io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $this->io->expects($this->once())
            ->method('note')
            ->with($this->anything());

        $this->responder->respond($this->io, $response);
    }

    public function testRespondRendersTableOnSuccess(): void
    {
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = SearchResponse::success([$issue], 'project = TPW');

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
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $io = new SymfonyStyle($input, $output);
        $logger = new Logger($io, []);
        $responder = new SearchResponder(new ResponderHelper($this->translationService, null), [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ], $logger);
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = SearchResponse::success([$issue], 'project = TPW');

        $responder->respond($io, $response);

        $out = $output->fetch();
        $this->assertStringContainsString('search.section', $out);
        $this->assertStringContainsString('project = TPW', $out);
    }

    public function testRespondShowsVerboseOutputWithoutColorHelperUsesFallback(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $io = new SymfonyStyle($input, $output);
        $logger = new Logger($io, []);
        $responder = new SearchResponder(new ResponderHelper($this->translationService, null), [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ], $logger);
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = SearchResponse::success([$issue], 'project = TPW');

        $responder->respond($io, $response);

        $out = $output->fetch();
        $this->assertStringContainsString('project = TPW', $out);
    }

    public function testRespondShowsVerboseOutputWithColorHelper(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $io = new SymfonyStyle($input, $output);
        $colorHelper = $this->createMock(ColorHelper::class);
        $colorHelper->method('registerStyles')->willReturnCallback(function (): void {
        });
        $colorHelper->method('format')->willReturnCallback(fn ($_, $text) => is_string($text) ? $text : '');
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $logger = new Logger($io, []);
        $responder = new SearchResponder($helper, [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ], $logger);
        $issue = new WorkItem('1000', 'TPW-35', 'Title', 'To Do', 'User', 'desc', [], 'Task');
        $response = SearchResponse::success([$issue], 'project = TPW');

        $responder->respond($io, $response);

        $out = $output->fetch();
        $this->assertStringContainsString('search.section', $out);
        $this->assertStringContainsString('project = TPW', $out);
    }

    public function testRespondWithColorHelperRegistersStyles(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new SearchResponder($helper, [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ], $this->createLogger($io));
        $response = SearchResponse::success([], 'project = TPW');

        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($io);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->once())
            ->method('note');

        $responder->respond($io, $response);
    }

    public function testRespondJsonReturnsSerializedResults(): void
    {
        $issue = new WorkItem('1', 'PROJ-1', 'Test', 'Open', 'user', '', [], 'Story');
        $response = SearchResponse::success([$issue], 'project = PROJ');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame('project = PROJ', $result->data['jql']);
        $this->assertCount(1, $result->data['issues']);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = SearchResponse::error('API error');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertSame('API error', $result->error);
    }
}
