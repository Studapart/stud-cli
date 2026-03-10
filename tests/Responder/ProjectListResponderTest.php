<?php

namespace App\Tests\Responder;

use App\DTO\Project;
use App\Enum\OutputFormat;
use App\Responder\ProjectListResponder;
use App\Response\ProjectListResponse;
use App\Service\ColorHelper;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectListResponderTest extends CommandTestCase
{
    private ProjectListResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $helper = new ResponderHelper($this->translationService);
        $this->responder = new ProjectListResponder($helper);
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

        $this->responder->respond($io, $response);
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

        $this->responder->respond($io, $response);
    }

    public function testRespondWithColorHelperRegistersStyles(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $responder = new ProjectListResponder($helper);
        $response = ProjectListResponse::success([]);
        $io = $this->createMock(SymfonyStyle::class);

        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($io);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->once())
            ->method('note');

        $responder->respond($io, $response);
    }

    public function testRespondJsonReturnsSerializedProjects(): void
    {
        $project = new Project('PROJ', 'My Project');
        $response = ProjectListResponse::success([$project]);
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data['projects']);
        $this->assertSame('PROJ', $result->data['projects'][0]['key']);
        $this->assertSame('My Project', $result->data['projects'][0]['name']);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = ProjectListResponse::error('API error');
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertSame('API error', $result->error);
    }

    public function testRespondCliReturnsNull(): void
    {
        $response = ProjectListResponse::success([]);
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Cli);

        $this->assertNull($result);
    }
}
