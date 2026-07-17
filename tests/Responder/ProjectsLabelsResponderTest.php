<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Enum\OutputFormat;
use App\Responder\ProjectsLabelsResponder;
use App\Response\ProjectsLabelsResponse;
use App\Service\Logger;
use App\Service\MessageRenderer;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectsLabelsResponderTest extends CommandTestCase
{
    private SymfonyStyle&\PHPUnit\Framework\MockObject\MockObject $io;

    private ProjectsLabelsResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $helper = new ResponderHelper($this->translationService);
        $this->io = $this->createMock(SymfonyStyle::class);
        $this->responder = new ProjectsLabelsResponder($helper, $this->createLogger($this->io));
    }

    public function testRespondRendersTableOnSuccess(): void
    {
        $response = ProjectsLabelsResponse::success([
            [
                'id' => 'group-1',
                'name' => 'Type',
                'labels' => [
                    ['id' => 'label-1', 'name' => 'Bug', 'color' => '#ff0000'],
                ],
            ],
        ]);

        $this->io->expects($this->once())->method('section');
        $this->io->expects($this->once())->method('table');

        $this->responder->respond($this->io, $response);
    }

    public function testRespondJsonReturnsGroups(): void
    {
        $response = ProjectsLabelsResponse::success([
            [
                'id' => 'group-1',
                'name' => 'Type',
                'labels' => [
                    ['id' => 'label-1', 'name' => 'Bug', 'color' => '#ff0000'],
                ],
            ],
        ]);

        $agentResponse = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($agentResponse);
        $payload = $agentResponse->toPayload();
        $this->assertTrue($payload['success']);
        $this->assertCount(1, $payload['data']['groups']);
        $this->assertSame('label-1', $payload['data']['groups'][0]['labels'][0]['id']);
    }

    public function testRespondJsonReturnsErrorPayload(): void
    {
        $response = ProjectsLabelsResponse::error('project.labels.error_no_provider');

        $agentResponse = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($agentResponse);
        $payload = $agentResponse->toPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('project.labels.error_no_provider', $payload['error']);
    }

    public function testRespondReturnsNullOnError(): void
    {
        $response = ProjectsLabelsResponse::error('project.labels.error_no_provider');

        $this->io->expects($this->never())->method('table');

        $this->assertNull($this->responder->respond($this->io, $response));
    }

    public function testRespondRendersNoticeForJiraProvider(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('note')
            ->with(
                Logger::VERBOSITY_NORMAL,
                'project.labels.labels_not_supported_for_jira',
            );
        $responder = new ProjectsLabelsResponder(
            new ResponderHelper($this->translationService),
            $logger,
            new MessageRenderer($this->translationService),
        );
        $response = ProjectsLabelsResponse::success([], [
            ResponseMessage::notice(MessageRef::key('project.labels.labels_not_supported_for_jira')),
        ]);

        $this->assertNull($responder->respond($this->io, $response));
    }

    public function testRespondRendersWarningsWhenNoGroups(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                Logger::VERBOSITY_NORMAL,
                'project.labels.no_label_groups {"project":"SCI"}',
            );
        $responder = new ProjectsLabelsResponder(
            new ResponderHelper($this->translationService),
            $logger,
            new MessageRenderer($this->translationService),
        );
        $response = ProjectsLabelsResponse::success([], [
            ResponseMessage::warning(MessageRef::key('project.labels.no_label_groups', ['project' => 'SCI'])),
        ]);

        $this->assertNull($responder->respond($this->io, $response));
    }
}
