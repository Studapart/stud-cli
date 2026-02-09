<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Responder\ConfigShowResponder;
use App\Response\ConfigShowResponse;
use App\Service\ColorHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfigShowResponderTest extends CommandTestCase
{
    private ConfigShowResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responder = new ConfigShowResponder($this->translationService, null);
    }

    public function testRespondOutputsErrorWhenResponseIsNotSuccess(): void
    {
        $response = ConfigShowResponse::error('config.show.no_config_found');
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('error')
            ->with($this->callback(function ($message) {
                return is_string($message) && str_contains($message, 'config.show.no_config_found');
            }));

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersGlobalSectionAndDefinitionListOnSuccess(): void
    {
        $response = ConfigShowResponse::success(['LANGUAGE' => 'en', 'JIRA_URL' => 'https://jira.example.com'], null);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('definitionList')
            ->with($this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersProjectSectionWhenProjectConfigPresent(): void
    {
        $response = ConfigShowResponse::success(
            ['LANGUAGE' => 'en'],
            ['projectKey' => 'PROJ']
        );
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->exactly(2))
            ->method('section')
            ->with($this->anything());
        $io->expects($this->exactly(2))
            ->method('definitionList')
            ->with($this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondDoesNotRenderProjectSectionWhenProjectConfigEmpty(): void
    {
        $response = ConfigShowResponse::success(['LANGUAGE' => 'en'], null);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section');
        $io->expects($this->once())
            ->method('definitionList');

        $this->responder->respond($io, $response);
    }

    public function testRespondWithColorHelperRegistersStylesAndFormatsOutput(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $responder = new ConfigShowResponder($this->translationService, $colorHelper);
        $response = ConfigShowResponse::success(['LANGUAGE' => 'en'], null);
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

    public function testRespondFormatsBooleanAndArrayValues(): void
    {
        $response = ConfigShowResponse::success([
            'JIRA_TRANSITION_ENABLED' => true,
            'nested' => ['a' => 1],
        ], null);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section');
        $io->expects($this->once())
            ->method('definitionList')
            ->with($this->callback(function (array $firstRow) {
                $values = array_values($firstRow);

                return in_array('true', $values, true) || in_array('{"a":1}', $values, true);
            }), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersSectionOnlyWhenGlobalConfigEmpty(): void
    {
        $response = ConfigShowResponse::success([], null);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section');
        $io->expects($this->never())
            ->method('definitionList');

        $this->responder->respond($io, $response);
    }

    public function testRespondFormatsNonScalarValueAsEmptyString(): void
    {
        $response = ConfigShowResponse::success(['key' => new \stdClass()], null);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section');
        $io->expects($this->once())
            ->method('definitionList')
            ->with($this->callback(function (array $row) {
                $values = array_values($row);

                return $values !== [] && $values[0] === '';
            }));

        $this->responder->respond($io, $response);
    }
}
