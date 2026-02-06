<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Responder\ConfigValidateResponder;
use App\Response\ConfigValidateResponse;
use App\Service\ColorHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfigValidateResponderTest extends CommandTestCase
{
    private ConfigValidateResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responder = new ConfigValidateResponder($this->translationService, null);
    }

    public function testRespondOutputsSectionAndDefinitionListWhenAllOk(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_OK,
            null,
            ConfigValidateResponse::STATUS_OK,
            null
        );
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('definitionList')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondOutputsErrorWhenResponseHasError(): void
    {
        $response = ConfigValidateResponse::error('config.error.not_found');
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('error')
            ->with($this->callback(function ($arg) {
                return is_array($arg) && count($arg) >= 1;
            }));
        $io->expects($this->never())
            ->method('section');

        $this->responder->respond($io, $response);
    }

    public function testRespondOutputsSectionWithFailStatus(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_FAIL,
            'Connection refused',
            ConfigValidateResponse::STATUS_OK,
            null
        );
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section');
        $io->expects($this->once())
            ->method('definitionList')
            ->with(
                $this->callback(function (array $jiraRow) {
                    $value = array_values($jiraRow)[0] ?? '';

                    return is_string($value) && str_contains(strtolower($value), 'fail') && str_contains($value, 'Connection refused');
                }),
                $this->anything()
            );

        $this->responder->respond($io, $response);
    }

    public function testRespondOutputsSkippedStatus(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_SKIPPED,
            null,
            ConfigValidateResponse::STATUS_SKIPPED,
            null
        );
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section');
        $io->expects($this->once())
            ->method('definitionList')
            ->with(
                $this->callback(function (array $row) {
                    $value = array_values($row)[0] ?? '';

                    return is_string($value) && str_contains($value, 'config.validate.status_skipped');
                }),
                $this->callback(function (array $row) {
                    $value = array_values($row)[0] ?? '';

                    return is_string($value) && str_contains($value, 'config.validate.status_skipped');
                })
            );

        $this->responder->respond($io, $response);
    }

    public function testRespondWithColorHelperRegistersStylesAndFormatsOutput(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $responder = new ConfigValidateResponder($this->translationService, $colorHelper);
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_OK,
            null,
            ConfigValidateResponse::STATUS_OK,
            null
        );
        $io = $this->createMock(SymfonyStyle::class);

        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->willReturnCallback(fn (string $color, string $text) => "<{$color}>{$text}</>");

        $io->expects($this->once())
            ->method('section');
        $io->expects($this->once())
            ->method('definitionList');

        $responder->respond($io, $response);
    }

    public function testRespondRendersBothRowsWhenOneOkOneSkipped(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_OK,
            null,
            ConfigValidateResponse::STATUS_SKIPPED,
            null
        );
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section');
        $io->expects($this->once())
            ->method('definitionList')
            ->with($this->isType('array'), $this->isType('array'));

        $this->responder->respond($io, $response);
    }
}
