<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Enum\OutputFormat;
use App\Responder\ConfigValidateResponder;
use App\Response\ConfigValidateResponse;
use App\Service\ColorHelper;
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
        $this->logger->expects($this->once())
            ->method('definitionList')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything());

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
        $this->logger->expects($this->once())->method('definitionList');
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
        $this->logger->expects($this->once())->method('definitionList');
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
        $this->logger->expects($this->once())
            ->method('definitionList')
            ->with(
                $this->anything(),
                $this->callback(function (array $jiraRow) {
                    $value = array_values($jiraRow)[0] ?? '';

                    return is_string($value) && str_contains(strtolower($value), 'fail') && str_contains($value, 'Connection refused');
                }),
                $this->anything(),
                $this->anything(),
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

        $this->logger->expects($this->once())
            ->method('section')
            ->with($this->anything(), $this->anything());
        $this->logger->expects($this->once())
            ->method('definitionList')
            ->with(
                $this->anything(),
                $this->callback(function (array $row) {
                    $value = array_values($row)[0] ?? '';

                    return is_string($value) && str_contains($value, 'config.validate.status_skipped');
                }),
                $this->callback(function (array $row) {
                    $value = array_values($row)[0] ?? '';

                    return is_string($value) && str_contains($value, 'config.validate.status_skipped');
                }),
                $this->callback(function (array $row) {
                    $value = array_values($row)[0] ?? '';

                    return is_string($value) && str_contains($value, 'config.validate.status_skipped');
                }),
            );

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
        $logger->expects($this->atLeastOnce())
            ->method('definitionList')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything());

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
        $this->logger->expects($this->once())
            ->method('definitionList')
            ->with($this->anything(), $this->isType('array'), $this->isType('array'), $this->isType('array'));

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

        $result = $this->responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame('ok', $result->data['jiraStatus']);
        $this->assertSame('ok', $result->data['gitStatus']);
        $this->assertSame('skipped', $result->data['linearStatus']);
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

        $result = $this->responder->respond($io, $response, OutputFormat::Json);

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
        $this->logger->expects($this->once())
            ->method('definitionList')
            ->with(
                $this->anything(),
                $this->callback(function (array $jiraRow) {
                    $value = array_values($jiraRow)[0] ?? '';

                    return is_string($value)
                        && str_contains(strtolower($value), 'fail')
                        && str_contains($value, 'config.validate.error_jira_not_configured');
                }),
                $this->anything(),
                $this->anything(),
            );

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
        $this->logger->expects($this->once())
            ->method('definitionList')
            ->with(
                $this->anything(),
                $this->callback(function (array $jiraRow) {
                    $value = array_values($jiraRow)[0] ?? '';

                    return is_string($value)
                        && str_contains(strtolower($value), 'fail')
                        && ! str_contains($value, '(');
                }),
                $this->anything(),
                $this->anything(),
            );

        $this->responder->respond($io, $response);
    }
}
