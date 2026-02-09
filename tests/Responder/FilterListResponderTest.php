<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\Filter;
use App\Responder\FilterListResponder;
use App\Response\FilterListResponse;
use App\Service\ColorHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterListResponderTest extends CommandTestCase
{
    private FilterListResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responder = new FilterListResponder($this->translationService, null);
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
        $responder = new FilterListResponder($this->translationService, $colorHelper);
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
}
