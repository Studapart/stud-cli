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
    private SymfonyStyle&\PHPUnit\Framework\MockObject\MockObject $io;

    private ProjectListResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $helper = new ResponderHelper($this->translationService);
        $this->io = $this->createMock(SymfonyStyle::class);
        $this->responder = new ProjectListResponder($helper, $this->createLogger($this->io));
    }

    public function testRespondReturnsZeroOnEmptyProjects(): void
    {
        $response = ProjectListResponse::success([]);

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
        $project = new Project('PROJ', 'My Project');
        $response = ProjectListResponse::success([$project]);

        $this->io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $this->io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($this->io, $response);
    }

    public function testRespondWithColorHelperRegistersStyles(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $responder = new ProjectListResponder($helper, $this->createLogger($this->io));
        $response = ProjectListResponse::success([]);

        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($this->io);
        $this->io->expects($this->once())
            ->method('section');
        $this->io->expects($this->once())
            ->method('note');

        $responder->respond($this->io, $response);
    }

    public function testRespondJsonReturnsSerializedProjects(): void
    {
        $project = new Project('PROJ', 'My Project');
        $response = ProjectListResponse::success([$project]);

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data['projects']);
        $this->assertSame('PROJ', $result->data['projects'][0]['key']);
        $this->assertSame('My Project', $result->data['projects'][0]['name']);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = ProjectListResponse::error('API error');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertSame('API error', $result->error);
    }

    public function testRespondCliReturnsNull(): void
    {
        $response = ProjectListResponse::success([]);

        $result = $this->responder->respond($this->io, $response, OutputFormat::Cli);

        $this->assertNull($result);
    }
}
