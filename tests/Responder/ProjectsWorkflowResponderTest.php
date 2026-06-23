<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Enum\OutputFormat;
use App\Responder\ProjectsWorkflowResponder;
use App\Response\ProjectsWorkflowResponse;
use App\Service\Logger;
use App\Service\MessageRenderer;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectsWorkflowResponderTest extends CommandTestCase
{
    private SymfonyStyle&\PHPUnit\Framework\MockObject\MockObject $io;

    private ProjectsWorkflowResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $helper = new ResponderHelper($this->translationService);
        $this->io = $this->createMock(SymfonyStyle::class);
        $this->responder = new ProjectsWorkflowResponder($helper, $this->createLogger($this->io));
    }

    public function testRespondRendersTableOnSuccess(): void
    {
        $response = ProjectsWorkflowResponse::success([
            ['id' => '11', 'name' => 'Start', 'targetStatus' => 'In Progress', 'provider' => 'jira'],
        ]);

        $this->io->expects($this->once())->method('section');
        $this->io->expects($this->once())->method('table');

        $this->responder->respond($this->io, $response);
    }

    public function testRespondJsonReturnsWorkflows(): void
    {
        $response = ProjectsWorkflowResponse::success([
            ['id' => '11', 'name' => 'Start', 'targetStatus' => 'In Progress', 'provider' => 'jira'],
        ]);

        $agentResponse = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($agentResponse);
        $payload = $agentResponse->toPayload();
        $this->assertTrue($payload['success']);
        $this->assertCount(1, $payload['data']['stateChanges']);
    }

    public function testRespondJsonReturnsErrorPayload(): void
    {
        $response = ProjectsWorkflowResponse::error('project.workflow.error_no_provider');

        $agentResponse = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($agentResponse);
        $payload = $agentResponse->toPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('project.workflow.error_no_provider', $payload['error']);
    }

    public function testRespondReturnsNullOnError(): void
    {
        $response = ProjectsWorkflowResponse::error('project.workflow.error_no_provider');

        $this->io->expects($this->never())->method('table');

        $this->assertNull($this->responder->respond($this->io, $response));
    }

    public function testRespondRendersWarningsWhenNoStateChanges(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                Logger::VERBOSITY_NORMAL,
                'project.workflow.no_state_changes {"project":"SCI"}',
            );
        $responder = new ProjectsWorkflowResponder(
            new ResponderHelper($this->translationService),
            $logger,
            new MessageRenderer($this->translationService),
        );
        $response = ProjectsWorkflowResponse::success([], [
            ResponseMessage::warning(MessageRef::key('project.workflow.no_state_changes', ['project' => 'SCI'])),
        ]);

        $this->assertNull($responder->respond($this->io, $response));
    }
}
