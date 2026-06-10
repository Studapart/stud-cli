<?php

declare(strict_types=1);

namespace App\Tests\Response;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Service\MessageRenderer;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;

class ResponseMessageTest extends TestCase
{
    public function testToPayloadRendersMessageRefWithRenderer(): void
    {
        $renderer = new MessageRenderer(new TranslationService('en', __DIR__ . '/../../src/resources/translations'));

        $payload = ResponseMessage::info(MessageRef::key('table.key'))->toPayload($renderer);

        $this->assertSame(['message' => 'Key'], $payload);
    }
}
