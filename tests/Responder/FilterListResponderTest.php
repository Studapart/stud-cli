<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\Filter;
use App\Enum\OutputFormat;
use App\Responder\FilterListResponder;
use App\Response\FilterListResponse;
use App\Service\ColorHelper;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterListResponderTest extends CommandTestCase
{
    private SymfonyStyle&\PHPUnit\Framework\MockObject\MockObject $io;

    private FilterListResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();
        $helper = new ResponderHelper($this->translationService);
        $this->io = $this->createMock(SymfonyStyle::class);
        $this->responder = new FilterListResponder($helper, $this->createLogger($this->io));
    }

    public function testRespondShowsNoteWhenNoFilters(): void
    {
        $response = FilterListResponse::success([]);

        $this->io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $this->io->expects($this->once())
            ->method('note')
            ->with($this->callback(fn ($m) => is_string($m) && $m !== ''));

        $this->responder->respond($this->io, $response);
    }

    public function testRespondRendersTableWhenFiltersPresent(): void
    {
        $filter = new Filter('My Filter', 'Description');
        $response = FilterListResponse::success([$filter]);

        $this->io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $this->io->expects($this->never())
            ->method('note');
        $this->io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($this->io, $response);
    }

    public function testRespondWithColorHelperAppliesFormatting(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $responder = new FilterListResponder($helper, $this->createLogger($this->io));
        $filter = new Filter('My Filter', 'Description');
        $response = FilterListResponse::success([$filter]);

        $colorHelper->expects($this->atLeastOnce())
            ->method('registerStyles')
            ->with($this->io);
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->willReturnCallback(fn ($color, $text) => "<{$color}>{$text}</>");

        $this->io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $this->io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $responder->respond($this->io, $response);
    }

    public function testRespondJsonReturnsSerializedFilters(): void
    {
        $filter = new Filter('My Filter', 'Description');
        $response = FilterListResponse::success([$filter]);

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data['filters']);
        $this->assertSame('My Filter', $result->data['filters'][0]['name']);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = FilterListResponse::error('API error');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertSame('API error', $result->error);
    }
}
