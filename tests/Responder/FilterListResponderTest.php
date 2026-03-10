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
    private FilterListResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();
        $helper = new ResponderHelper($this->translationService);
        $this->responder = new FilterListResponder($helper);
    }

    public function testRespondShowsNoteWhenNoFilters(): void
    {
        $response = FilterListResponse::success([]);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('note')
            ->with($this->callback(fn ($m) => is_string($m) && $m !== ''));

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersTableWhenFiltersPresent(): void
    {
        $filter = new Filter('My Filter', 'Description');
        $response = FilterListResponse::success([$filter]);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->never())
            ->method('note');
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondWithColorHelperAppliesFormatting(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $responder = new FilterListResponder($helper);
        $filter = new Filter('My Filter', 'Description');
        $response = FilterListResponse::success([$filter]);
        $io = $this->createMock(SymfonyStyle::class);

        $colorHelper->expects($this->atLeastOnce())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->willReturnCallback(fn ($color, $text) => "<{$color}>{$text}</>");

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('table')
            ->with($this->anything(), $this->anything());

        $responder->respond($io, $response);
    }

    public function testRespondJsonReturnsSerializedFilters(): void
    {
        $filter = new Filter('My Filter', 'Description');
        $response = FilterListResponse::success([$filter]);
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data['filters']);
        $this->assertSame('My Filter', $result->data['filters'][0]['name']);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = FilterListResponse::error('API error');
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertSame('API error', $result->error);
    }
}
