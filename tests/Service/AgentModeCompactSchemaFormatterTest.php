<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AgentModeCompactSchemaFormatter;
use App\Service\AgentModeSchemaGenerator;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;

class AgentModeCompactSchemaFormatterTest extends TestCase
{
    public function testTextReturnsFallbackWithoutTranslator(): void
    {
        $formatter = new AgentModeCompactSchemaFormatter();

        $this->assertSame('Fallback', $formatter->text('agent.missing', 'Fallback'));
    }

    public function testTextReturnsTranslationOrFallbackWhenKeyIsUntranslated(): void
    {
        $formatter = new AgentModeCompactSchemaFormatter($this->translator(['agent.known' => 'Known']));

        $this->assertSame('Known', $formatter->text('agent.known', 'Fallback'));
        $this->assertSame('Fallback', $formatter->text('agent.unknown', 'Fallback'));
    }

    public function testMetaUsesTranslatedTextsInStableKeyOrder(): void
    {
        $formatter = new AgentModeCompactSchemaFormatter($this->translator(['agent.meta.description' => 'Translated']));

        $this->assertSame([
            'description' => 'Translated',
            'generatedBy' => AgentModeSchemaGenerator::GENERATED_BY,
            'defaultDiscovery' => 'Default lists essential commands only.',
            'fullDiscovery' => 'Set essential=false to list every command.',
            'commandDiscovery' => 'Set command=name for expanded command help.',
        ], $formatter->meta());
    }

    public function testCommandDropsExpandedFieldsAndCompactsOutputWithData(): void
    {
        $formatter = new AgentModeCompactSchemaFormatter($this->translator(['agent.command.demo' => 'Short demo.']));

        $command = $formatter->command([
            'name' => 'demo',
            'description' => 'Long demo description.',
            'parameters' => ['options' => [], 'arguments' => []],
            'input' => ['properties' => ['compact' => AgentModeCompactSchemaFormatter::COMPACT_PROPERTY, 'key' => 'string']],
            'output' => [
                'description' => 'Demo output.',
                'success' => ['success' => true, 'data' => ['id' => 'int']],
                'compactSuccess' => ['success' => true, 'data' => ['id' => 'int']],
            ],
        ]);

        $this->assertSame([
            'name' => 'demo',
            'description' => 'Short demo.',
            'input' => ['properties' => ['key' => 'string']],
            'output' => [
                'description' => 'Demo output.',
                'compact' => ['successData', 'error'],
                'full' => ['successData', 'error'],
                'data' => ['id' => 'int'],
            ],
        ], $command);
    }

    public function testCommandMarksCompletionOnlyOutputAsSuccessOnly(): void
    {
        $command = (new AgentModeCompactSchemaFormatter())->command([
            'name' => 'demo',
            'description' => 'Demo.',
            'input' => ['properties' => []],
            'output' => [
                'success' => ['success' => true, 'data' => '(undescribed)'],
                'compactSuccess' => ['success' => true],
            ],
        ]);

        $this->assertSame([
            'description' => null,
            'compact' => ['successOnly', 'error'],
            'full' => ['successData', 'error'],
            'data' => '(undescribed)',
        ], $command['output']);
    }

    public function testCommandOmitsDataWhenSuccessSchemaIsMissing(): void
    {
        $command = (new AgentModeCompactSchemaFormatter())->command([
            'name' => 'demo',
            'description' => 'Demo.',
            'input' => ['properties' => []],
            'output' => ['success' => 'invalid'],
        ]);

        $this->assertSame([
            'description' => null,
            'compact' => ['successOnly', 'error'],
            'full' => ['successData', 'error'],
        ], $command['output']);
    }

    public function testGlobalSectionsExposeCompactInputAndResponseSchemas(): void
    {
        $sections = (new AgentModeCompactSchemaFormatter($this->translator(['agent.response.note' => 'Note.'])))->globalSections();

        $this->assertSame(['compact' => AgentModeCompactSchemaFormatter::COMPACT_PROPERTY], $sections['globalInput']);
        $this->assertSame(['successOnly', 'successData', 'error', 'diagnostics', 'message', 'note'], array_keys($sections['responseSchemas']));
        $this->assertSame('Note.', $sections['responseSchemas']['note']);
    }

    /**
     * @param array<string, string> $translations
     */
    private function translator(array $translations): TranslationService
    {
        $translator = $this->createStub(TranslationService::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $translations[$id] ?? $id
        );

        return $translator;
    }
}
