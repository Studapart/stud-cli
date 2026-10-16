<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\ConfigValidateIntegrationPing;
use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Enum\OutputFormat;
use App\Responder\ConfigValidateResponder;
use App\Response\ConfigValidateResponse;
use App\Service\ColorHelper;
use App\Service\ConfigReadinessEvaluation;
use App\Service\ConfigReadinessEvaluator;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfigValidateResponderTest extends CommandTestCase
{
    private ConfigValidateResponder $responder;

    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $helper = new ResponderHelper($this->translationService);
        $this->logger = $this->createMock(Logger::class);
        $this->responder = new ConfigValidateResponder($helper, $this->logger);
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

        $this->logger->expects($this->once())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->exactly(2))
            ->method('definitionList');

        $this->responder->respond($io, $response);
    }

    public function testRespondOutputsCliWarnings(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_OK,
            null,
            ConfigValidateResponse::STATUS_OK,
            null,
            messages: [
                ResponseMessage::warning(MessageRef::key('config.validate.warn_gitlab_token_missing')),
            ],
        );
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())->method('section');
        $this->logger->expects($this->exactly(2))->method('definitionList');
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->anything(), $this->isType('string'));

        $this->responder->respond($io, $response);
    }

    public function testRespondOutputsCliWarningsForPlainStringMessages(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_OK,
            null,
            ConfigValidateResponse::STATUS_OK,
            null,
            messages: [
                ResponseMessage::warning('plain warning'),
            ],
        );
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())->method('section');
        $this->logger->expects($this->exactly(2))->method('definitionList');
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->anything(), 'plain warning');

        $this->responder->respond($io, $response);
    }

    public function testRespondOutputsErrorWhenResponseHasError(): void
    {
        $response = ConfigValidateResponse::error('config.error.not_found');
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->anything(), $this->callback(function ($arg) {
                return is_array($arg) && count($arg) >= 1;
            }));
        $this->logger->expects($this->never())
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

        $this->logger->expects($this->once())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->exactly(2))
            ->method('definitionList');

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

        $this->logger->expects($this->once())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->exactly(2))
            ->method('definitionList');

        $this->responder->respond($io, $response);
    }

    public function testRespondWithColorHelperRegistersStylesAndFormatsOutput(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $logger = $this->createMock(Logger::class);
        $responder = new ConfigValidateResponder($helper, $logger);
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_OK,
            null,
            ConfigValidateResponse::STATUS_OK,
            null
        );
        $io = $this->createMock(SymfonyStyle::class);

        $logger->expects($this->once())
            ->method('registerStyles')
            ->with($colorHelper);
        $logger->expects($this->atLeastOnce())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $logger->expects($this->atLeast(2))
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

        $this->logger->expects($this->once())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->exactly(2))
            ->method('definitionList');

        $this->responder->respond($io, $response);
    }

    public function testRespondJsonReturnsValidationData(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_OK,
            null,
            ConfigValidateResponse::STATUS_OK,
            null
        );
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json, false);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame('ok', $result->data['jiraStatus']);
        $this->assertSame('ok', $result->data['gitStatus']);
        $this->assertSame('skipped', $result->data['linearStatus']);
    }

    private function partialSuccessResponse(): ConfigValidateResponse
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
            'linear' => new ConfigValidateIntegrationPing(
                ConfigValidateResponse::STATUS_FAIL,
                MessageRef::key('config.validate.error_linear_ping', ['error' => 'down']),
            ),
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];
        $evaluation = new ConfigReadinessEvaluation(
            ConfigReadinessEvaluator::ROLLUP_PARTIAL,
            ConfigReadinessEvaluator::ROLLUP_OK,
            true,
            ['Linear connectivity check failed (non-blocking for this project).'],
        );

        return ConfigValidateResponse::fromEvaluation($evaluation, $integrations, 'github');
    }

    public function testRespondJsonPartialCompactIncludesRollupsOnly(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $result = $this->responder->respond($io, $this->partialSuccessResponse(), OutputFormat::Json, true);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame('partial', $result->data['rollups']['global'] ?? null);
        $this->assertArrayNotHasKey('jiraStatus', $result->data);
    }

    public function testRespondJsonPartialNonCompactIncludesFullData(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $result = $this->responder->respond($io, $this->partialSuccessResponse(), OutputFormat::Json, false);

        $this->assertNotNull($result);
        $this->assertSame('ok', $result->data['jiraStatus'] ?? null);
        $this->assertSame('fail', $result->data['linearStatus'] ?? null);
    }

    public function testRespondCliShowsProjectRollupWhenApplicable(): void
    {
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())->method('section');
        $this->logger->expects($this->exactly(2))->method('definitionList');

        $this->responder->respond($io, $this->partialSuccessResponse());
    }

    public function testRespondJsonFailureCompactIncludesRollupsAndIntegrations(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_FAIL,
            MessageRef::key('config.validate.error_jira_not_configured'),
            ConfigValidateResponse::STATUS_OK,
            null,
            gitProvider: 'github',
        );
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json, true);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertArrayHasKey('rollups', $result->data);
        $this->assertArrayHasKey('integrations', $result->data);
        $this->assertArrayNotHasKey('jiraStatus', $result->data);
    }

    public function testRespondJsonFailureNonCompactIncludesFlatAliases(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_FAIL,
            MessageRef::key('config.validate.error_jira_not_configured'),
            ConfigValidateResponse::STATUS_OK,
            null,
            gitProvider: 'github',
        );
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json, false);

        $this->assertNotNull($result);
        $this->assertSame('fail', $result->data['jiraStatus'] ?? null);
    }

    public function testRespondJsonCompactCleanSuccessOmitsData(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_OK,
            null,
            ConfigValidateResponse::STATUS_OK,
            null,
            gitProvider: 'github',
        );
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json, true);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertFalse($result->hasData);
    }

    public function testRespondJsonIncludesWarningDiagnostics(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_OK,
            null,
            ConfigValidateResponse::STATUS_OK,
            null,
            messages: [
                ResponseMessage::warning(MessageRef::key('config.validate.warn_gitlab_token_missing')),
            ],
        );
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertArrayHasKey('warnings', $result->diagnostics);
        $this->assertCount(1, $result->diagnostics['warnings']);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_FAIL,
            'Connection refused',
            ConfigValidateResponse::STATUS_OK,
            null
        );
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
    }

    public function testRespondJsonTranslatesMessageRefAndPlainComponentMessages(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_OK,
            MessageRef::key('config.validate.error_jira_ping', ['error' => 'timeout']),
            ConfigValidateResponse::STATUS_OK,
            'plain git detail',
            ConfigValidateResponse::STATUS_SKIPPED,
            MessageRef::key('config.validate.error_git_not_configured'),
        );
        $io = $this->createMock(SymfonyStyle::class);

        $result = $this->responder->respond($io, $response, OutputFormat::Json, false);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame('plain git detail', $result->data['gitMessage']);
        $this->assertNotNull($result->data['jiraMessage']);
        $this->assertNotNull($result->data['linearMessage']);
    }

    public function testRespondOutputsFailStatusWithMessageRef(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_FAIL,
            MessageRef::key('config.validate.error_jira_not_configured'),
            ConfigValidateResponse::STATUS_OK,
            null,
        );
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())->method('section');
        $this->logger->expects($this->exactly(2))
            ->method('definitionList');

        $this->responder->respond($io, $response);
    }

    public function testRespondOutputsFailStatusWithoutDetailWhenMessageNull(): void
    {
        $response = ConfigValidateResponse::create(
            ConfigValidateResponse::STATUS_FAIL,
            null,
            ConfigValidateResponse::STATUS_OK,
            null,
        );
        $io = $this->createMock(SymfonyStyle::class);

        $this->logger->expects($this->once())->method('section');
        $this->logger->expects($this->exactly(2))
            ->method('definitionList');

        $this->responder->respond($io, $response);
    }
}
