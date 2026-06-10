<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\Service\MessageRenderer;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;

class MessageRendererTest extends TestCase
{
    public function testRenderReturnsNullForNullMessage(): void
    {
        $renderer = new MessageRenderer(new TranslationService('en', __DIR__ . '/../../src/resources/translations'));

        $this->assertNull($renderer->render(null));
    }

    public function testRenderReturnsPlainString(): void
    {
        $renderer = new MessageRenderer(new TranslationService('en', __DIR__ . '/../../src/resources/translations'));

        $this->assertSame('already rendered', $renderer->render('already rendered'));
    }

    public function testAgentRendererUsesAgentDomainOverride(): void
    {
        $renderer = new MessageRenderer(new TranslationService('vi', __DIR__ . '/../../src/resources/translations'), agent: true);

        $this->assertSame('key', $renderer->render(MessageRef::key('table.key')));
    }

    public function testAgentRendererFallsBackToEnglishDefaultDomain(): void
    {
        $renderer = new MessageRenderer(new TranslationService('vi', __DIR__ . '/../../src/resources/translations'), agent: true);

        $this->assertSame('Manual', $renderer->render(MessageRef::key('help.title')));
    }

    public function testRenderForAgentDelegatesToAgentStrategy(): void
    {
        $renderer = new MessageRenderer(new TranslationService('vi', __DIR__ . '/../../src/resources/translations'));

        $this->assertNull($renderer->renderForAgent(null));
        $this->assertSame('already rendered', $renderer->renderForAgent('already rendered'));
        $this->assertSame('key', $renderer->renderForAgent(MessageRef::key('table.key')));
    }
}
