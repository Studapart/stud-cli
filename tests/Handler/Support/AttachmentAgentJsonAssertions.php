<?php

declare(strict_types=1);

namespace App\Tests\Handler\Support;

use App\Enum\OutputFormat;
use App\Responder\ItemDownloadResponder;
use App\Responder\ItemUploadResponder;
use App\Response\ItemDownloadResponse;
use App\Response\ItemUploadResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared assertions for upload/download batch handler results and agent JSON payloads.
 */
trait AttachmentAgentJsonAssertions
{
    protected function assertUploadHandlerBatchShape(ItemUploadResponse $response): void
    {
        TestCase::assertTrue($response->isSuccess());
        $this->assertBatchFilesAndErrorsShape($response->files, $response->errors);
    }

    protected function assertDownloadHandlerBatchShape(ItemDownloadResponse $response): void
    {
        TestCase::assertTrue($response->isSuccess());
        $this->assertBatchFilesAndErrorsShape($response->files, $response->errors);
    }

    /**
     * @param list<array{filename: string, path: string}>                         $files
     * @param list<array{filename: string|null, message: mixed}> $errors
     */
    protected function assertBatchFilesAndErrorsShape(array $files, array $errors): void
    {
        foreach ($files as $file) {
            TestCase::assertArrayHasKey('filename', $file);
            TestCase::assertArrayHasKey('path', $file);
            TestCase::assertIsString($file['filename']);
            TestCase::assertIsString($file['path']);
        }

        foreach ($errors as $error) {
            TestCase::assertArrayHasKey('message', $error);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function uploadAgentPayload(ItemUploadResponse $response, TranslationService $translator): array
    {
        $helper = new ResponderHelper($translator);
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createAttachmentTestLogger($io);
        $responder = new ItemUploadResponder($helper, [], $logger);
        $agent = $responder->respond($io, $response, OutputFormat::Json);
        TestCase::assertNotNull($agent);

        return $agent->toPayload();
    }

    /**
     * @return array<string, mixed>
     */
    protected function downloadAgentPayload(ItemDownloadResponse $response, TranslationService $translator): array
    {
        $helper = new ResponderHelper($translator);
        $io = $this->createMock(SymfonyStyle::class);
        $logger = $this->createAttachmentTestLogger($io);
        $responder = new ItemDownloadResponder($helper, [], $logger);
        $agent = $responder->respond($io, $response, OutputFormat::Json);
        TestCase::assertNotNull($agent);

        return $agent->toPayload();
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function assertAgentBatchPayloadParity(array $payload): void
    {
        TestCase::assertTrue($payload['success'] ?? false);
        TestCase::assertArrayHasKey('data', $payload);
        TestCase::assertArrayHasKey('files', $payload['data']);
        TestCase::assertArrayHasKey('errors', $payload['data']);
        TestCase::assertIsArray($payload['data']['files']);
        TestCase::assertIsArray($payload['data']['errors']);
        $this->assertBatchFilesAndErrorsShape($payload['data']['files'], $payload['data']['errors']);

        foreach ($payload['data']['errors'] as $error) {
            TestCase::assertArrayHasKey('filename', $error);
            TestCase::assertArrayHasKey('message', $error);
            TestCase::assertIsString($error['message']);
        }
    }

    protected function createAttachmentTestLogger(SymfonyStyle $io): Logger
    {
        return new Logger($io, []);
    }
}
