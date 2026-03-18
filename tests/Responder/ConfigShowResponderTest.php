<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Enum\OutputFormat;
use App\Responder\ConfigShowResponder;
use App\Response\ConfigShowResponse;
use App\Service\ColorHelper;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfigShowResponderTest extends CommandTestCase
{
    private ConfigShowResponder $responder;

    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $helper = new ResponderHelper($this->translationService);
        $this->responder = new ConfigShowResponder($helper, $this->logger);
    }

    public function testRespondOutputsErrorWhenResponseIsNotSuccess(): void
    {
        $response = ConfigShowResponse::error('config.show.no_config_found');
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->anything(), $this->callback(function ($message) {
                return is_string($message) && str_contains($message, 'config.show.no_config_found');
            }));

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersGlobalSectionAndDefinitionListOnSuccess(): void
    {
        $response = ConfigShowResponse::success(['LANGUAGE' => 'en', 'JIRA_URL' => 'https://jira.example.com'], null);
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->once())
            ->method('definitionList')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersProjectSectionWhenProjectConfigPresent(): void
    {
        $response = ConfigShowResponse::success(
            ['LANGUAGE' => 'en'],
            ['projectKey' => 'PROJ']
        );
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->exactly(2))
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->exactly(2))
            ->method('definitionList')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondDoesNotRenderProjectSectionWhenProjectConfigEmpty(): void
    {
        $response = ConfigShowResponse::success(['LANGUAGE' => 'en'], null);
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->once())
            ->method('definitionList')
            ->with($this->anything(), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondWithColorHelperRegistersStylesAndFormatsOutput(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $responder = new ConfigShowResponder($helper, $this->logger);
        $response = ConfigShowResponse::success(['LANGUAGE' => 'en'], null);
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('registerStyles')
            ->with($colorHelper);
        $this->logger->expects($this->atLeastOnce())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->atLeastOnce())
            ->method('definitionList')
            ->with($this->anything(), $this->anything());

        $responder->respond($io, $response);
    }

    public function testRespondFormatsBooleanAndArrayValues(): void
    {
        $response = ConfigShowResponse::success([
            'JIRA_TRANSITION_ENABLED' => true,
            'nested' => ['a' => 1],
        ], null);
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->once())
            ->method('definitionList')
            ->with($this->anything(), $this->callback(function (array $firstRow) {
                $values = array_values($firstRow);

                return in_array('true', $values, true) || in_array('{"a":1}', $values, true);
            }), $this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersSectionOnlyWhenGlobalConfigEmpty(): void
    {
        $response = ConfigShowResponse::success([], null);
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->never())
            ->method('definitionList');

        $this->responder->respond($io, $response);
    }

    public function testRespondFormatsNonScalarValueAsEmptyString(): void
    {
        $response = ConfigShowResponse::success(['key' => new \stdClass()], null);
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->once())
            ->method('definitionList')
            ->with($this->anything(), $this->callback(function (array $row) {
                $values = array_values($row);

                return $values !== [] && $values[0] === '';
            }));

        $this->responder->respond($io, $response);
    }

    public function testRespondSingleKeyQuietOutputsOnlyRawValue(): void
    {
        $response = ConfigShowResponse::successSingleKey('LANGUAGE', 'en', 'global');
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('rawValue')
            ->with('en');

        $this->responder->respond($io, $response, true);
    }

    public function testRespondSingleKeyNotQuietRendersOneSectionOneRow(): void
    {
        $response = ConfigShowResponse::successSingleKey('baseBranch', 'main', 'project');
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->once())
            ->method('definitionList')
            ->with($this->anything(), $this->callback(function (array $row) {
                return count($row) === 1 && (isset($row['baseBranch']) || array_key_first($row) === 'baseBranch');
            }));

        $this->responder->respond($io, $response, false);
    }

    public function testRespondErrorWithParametersPassesThemToTranslator(): void
    {
        $response = ConfigShowResponse::error('config.show.key_not_allowed', ['%key%' => 'JIRA_API_TOKEN']);
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->anything(), $this->callback(function (string $message) {
                return str_contains($message, 'config.show.key_not_allowed') && str_contains($message, 'JIRA_API_TOKEN');
            }));

        $this->responder->respond($io, $response);
    }

    public function testRespondSingleKeyNotQuietWithColorHelperFormatsSectionAndRow(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $responder = new ConfigShowResponder($helper, $this->logger);
        $response = ConfigShowResponse::successSingleKey('LANGUAGE', 'en', 'global');
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('registerStyles')
            ->with($colorHelper);
        $this->logger->expects($this->once())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->once())
            ->method('definitionList')
            ->with($this->anything(), $this->anything());

        $responder->respond($io, $response, false);
    }

    public function testRespondJsonReturnsGlobalConfig(): void
    {
        $response = ConfigShowResponse::success(['LANGUAGE' => 'en'], null);
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, false, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame(['LANGUAGE' => 'en'], $result->data['globalConfig']);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = ConfigShowResponse::error('config.show.no_config_found');
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, false, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
    }

    public function testRespondJsonReturnsSingleKey(): void
    {
        $response = ConfigShowResponse::successSingleKey('LANGUAGE', 'en', 'global');
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, false, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame('LANGUAGE', $result->data['singleKey']);
    }
}
