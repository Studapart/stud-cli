<?php

namespace App\Tests\Responder;

use App\DTO\Project;
use App\Responder\ProjectListResponder;
use App\Response\ProjectListResponse;
use App\Service\ColorHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectListResponderTest extends CommandTestCase
{
    private ProjectListResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responder = new ProjectListResponder($this->translationService, null);
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
        $responder = new ProjectListResponder($this->translationService, $colorHelper);
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
}
