<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Enum\OutputFormat;
use App\Responder\ConfigProjectInitResponder;
use App\Response\ConfigProjectInitResponse;
use App\Service\ColorHelper;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfigProjectInitResponderTest extends TestCase
{
    public function testJsonSuccessIncludesUpdatedAndConfig(): void
    {
        $translator = $this->createMock(TranslationService::class);
        $helper = new ResponderHelper($translator, $this->createMock(ColorHelper::class));
        $logger = $this->createMock(Logger::class);
        $responder = new ConfigProjectInitResponder($helper, $logger);

        $response = ConfigProjectInitResponse::success(true, ['projectKey' => 'SCI']);
        $io = $this->createMock(SymfonyStyle::class);
        $json = $responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($json);
        $this->assertTrue($json->success);
        $this->assertSame(['updated' => true, 'projectConfig' => ['projectKey' => 'SCI']], $json->data);
    }

    public function testJsonErrorUsesErrorKey(): void
    {
        $translator = $this->createMock(TranslationService::class);
        $helper = new ResponderHelper($translator, null);
        $logger = $this->createMock(Logger::class);
        $responder = new ConfigProjectInitResponder($helper, $logger);

        $response = ConfigProjectInitResponse::error('config.project_init.not_git_repository');
        $io = $this->createMock(SymfonyStyle::class);
        $json = $responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($json);
        $this->assertFalse($json->success);
        $this->assertSame('config.project_init.not_git_repository', $json->error);
    }

    public function testCliErrorLogsTranslatedMessage(): void
    {
        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')
            ->with('config.project_init.failed', [])
            ->willReturn('Human error');

        $helper = new ResponderHelper($translator, null);
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('error')->with(Logger::VERBOSITY_NORMAL, 'Human error');

        $responder = new ConfigProjectInitResponder($helper, $logger);
        $response = ConfigProjectInitResponse::error('config.project_init.failed');
        $io = $this->createMock(SymfonyStyle::class);

        $this->assertNull($responder->respond($io, $response, OutputFormat::Cli));
    }

    public function testCliSuccessUpdatedShowsStatusAndDefinitionList(): void
    {
        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')->willReturnCallback(fn (string $key): string => $key);

        $colorHelper = $this->createMock(ColorHelper::class);
        $colorHelper->method('format')->willReturnArgument(1);

        $helper = new ResponderHelper($translator, $colorHelper);
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('section');
        $logger->expects($this->once())->method('text')->with(Logger::VERBOSITY_NORMAL, 'config.project_init.status_updated');
        $logger->expects($this->once())->method('definitionList');

        $responder = new ConfigProjectInitResponder($helper, $logger);
        $response = ConfigProjectInitResponse::success(true, [
            'flag' => true,
            'count' => 3,
            'nested' => ['a' => 1],
            'empty' => new \stdClass(),
        ]);
        $io = $this->createMock(SymfonyStyle::class);

        $this->assertNull($responder->respond($io, $response, OutputFormat::Cli));
    }

    public function testCliSuccessUnchangedSkipsDefinitionListWhenConfigEmpty(): void
    {
        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')->willReturn('msg');

        $helper = new ResponderHelper($translator, null);
        $logger = $this->createMock(Logger::class);
        $logger->method('section');
        $logger->method('text')->with(Logger::VERBOSITY_NORMAL, 'msg');
        $logger->expects($this->never())->method('definitionList');

        $responder = new ConfigProjectInitResponder($helper, $logger);
        $response = ConfigProjectInitResponse::success(false, []);
        $io = $this->createMock(SymfonyStyle::class);

        $this->assertNull($responder->respond($io, $response, OutputFormat::Cli));
    }

    public function testJsonErrorFallsBackWhenErrorKeyMissing(): void
    {
        $translator = $this->createMock(TranslationService::class);
        $helper = new ResponderHelper($translator, null);
        $logger = $this->createMock(Logger::class);
        $responder = new ConfigProjectInitResponder($helper, $logger);

        $ref = new \ReflectionClass(ConfigProjectInitResponse::class);
        $response = $ref->newInstanceWithoutConstructor();
        $ctor = $ref->getConstructor();
        $ctor->setAccessible(true);
        $ctor->invoke($response, false, null, false, []);

        $json = $responder->respond($this->createMock(SymfonyStyle::class), $response, OutputFormat::Json);

        $this->assertNotNull($json);
        $this->assertFalse($json->success);
        $this->assertSame('config.project_init.failed', $json->error);
    }
}
