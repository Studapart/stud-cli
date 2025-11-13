<?php

namespace App\Tests\Service;

use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;

class TranslationServiceTest extends TestCase
{
    private string $translationsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translationsPath = __DIR__ . '/../../src/resources/translations';
    }

    public function testGetLocale(): void
    {
        $service = new TranslationService('en', $this->translationsPath);
        $this->assertSame('en', $service->getLocale());

        $service = new TranslationService('fr', $this->translationsPath);
        $this->assertSame('fr', $service->getLocale());

        $service = new TranslationService('vi', $this->translationsPath);
        $this->assertSame('vi', $service->getLocale());
    }

    public function testTrans(): void
    {
        $service = new TranslationService('en', $this->translationsPath);
        $this->assertSame('Manual', $service->trans('help.title'));
        $this->assertSame('Key', $service->trans('table.key'));

        $service = new TranslationService('vi', $this->translationsPath);
        $this->assertSame('Hướng dẫn', $service->trans('help.title'));
        $this->assertSame('Khóa', $service->trans('table.key'));
    }

    public function testTransWithParameters(): void
    {
        $service = new TranslationService('en', $this->translationsPath);
        $result = $service->trans('item.start.section', ['key' => 'TPW-123']);
        $this->assertStringContainsString('TPW-123', $result);
        $this->assertStringContainsString('Starting work', $result);
    }
}

