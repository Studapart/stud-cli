<?php

namespace App\Tests\Responder;

use App\DTO\Project;
use App\Response\ProjectListResponse;
use App\Responder\ProjectListResponder;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectListResponderTest extends CommandTestCase
{
    private ProjectListResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responder = new ProjectListResponder($this->translationService);
    }

    public function testRespondReturnsOneOnError(): void
    {
        $response = ProjectListResponse::error('Jira API error');
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('error')
            ->with($this->callback(function ($message) {
                return is_string($message) && str_contains($message, 'Jira API error');
            }));

        $result = $this->responder->respond($io, $response);

        $this->assertSame(1, $result);
    }

    public function testRespondReturnsZeroOnEmptyProjects(): void
    {
        $response = ProjectListResponse::success([]);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('note')
            ->with($this->anything());

        $result = $this->responder->respond($io, $response);

        $this->assertSame(0, $result);
    }

    public function testRespondRendersTableOnSuccess(): void
    {
        $project = new Project('PROJ', 'My Project');
        $response = ProjectListResponse::success([$project]);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $result = $this->responder->respond($io, $response);

        $this->assertSame(0, $result);
    }
}

