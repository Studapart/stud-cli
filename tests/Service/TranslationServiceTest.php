<?php

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

    public function testTransRendersMessageRefParameters(): void
    {
        $service = new TranslationService('en', $this->translationsPath);

        $this->assertSame(
            "Switched to branch 'Key'.",
            $service->trans('branch.switch.success', ['branch' => MessageRef::key('table.key')])
        );
    }

    public function testAgentDomainTranslationsAreEnglishOnly(): void
    {
        $service = new TranslationService('vi', $this->translationsPath);

        $this->assertSame('Commit changes.', $service->trans('agent.command.commit', domain: 'agent', locale: 'en'));
        $this->assertSame(
            'Use this schema for normal calls. Query command help only when data semantics are unclear. Compact success omits data only when no reusable data exists; diagnostics are included when present.',
            $service->trans('agent.response.note', domain: 'agent', locale: 'en')
        );
    }

    public function testRenderForAgentPrefersAgentDomainAndFallsBackToEnglish(): void
    {
        $service = new TranslationService('vi', $this->translationsPath);

        $this->assertNull($service->renderForAgent(null));
        $this->assertSame('already rendered', $service->renderForAgent('already rendered'));
        $this->assertSame('key', $service->renderForAgent(MessageRef::key('table.key')));
        $this->assertSame('Manual', $service->renderForAgent(MessageRef::key('help.title')));
        $this->assertSame('missing fallback', $service->renderForAgent(MessageRef::key('missing.agent.key', fallback: 'missing fallback')));
    }

    public function testTransForAgentRendersMessageRefParametersWithAgentStrategy(): void
    {
        $service = new TranslationService('vi', $this->translationsPath);

        $this->assertSame(
            'switched to key',
            $service->transForAgent('branch.switch.success', ['branch' => MessageRef::key('table.key')])
        );
        $this->assertSame(
            'switched to main',
            $service->transForAgent('branch.switch.success', ['%branch%' => 'main'])
        );
    }

    public function testAgentTextHelpersPreserveFallbackText(): void
    {
        $service = new TranslationService('vi', $this->translationsPath);

        $this->assertSame('unknown.key', $service->transForAgentText('unknown.key'));
        $this->assertSame('', $service->renderForAgentText(null));
        $this->assertSame('fallback text', $service->renderForAgentText(MessageRef::key('unknown.key', fallback: 'fallback text')));
    }

    public function testRenderReturnsNullAndPlainStringsUnchanged(): void
    {
        $service = new TranslationService('en', $this->translationsPath);

        $this->assertNull($service->render(null));
        $this->assertSame('already rendered', $service->render('already rendered'));
    }

    public function testRenderTextReturnsEmptyStringForNull(): void
    {
        $service = new TranslationService('en', $this->translationsPath);

        $this->assertSame('', $service->renderText(null));
        $this->assertSame('Key', $service->renderText(MessageRef::key('table.key')));
    }

    /**
     * Flattens a nested array into dot-notation keys.
     *
     * @param array $array The nested array to flatten
     * @param string $prefix The prefix for the current level
     * @return array Flat array with dot-notation keys
     */
    private function flattenKeys(array $array, string $prefix = ''): array
    {
        $keys = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $fullKey));
            } else {
                $keys[] = $fullKey;
            }
        }

        return $keys;
    }

    public function testAllTranslationFilesHaveSameKeys(): void
    {
        // Load English as reference
        $englishFile = $this->translationsPath . '/messages.en.yaml';
        $this->assertFileExists($englishFile, 'English translation file (messages.en.yaml) must exist as reference');

        $englishData = Yaml::parseFile($englishFile);
        $this->assertIsArray($englishData, 'English translation file must be valid YAML');

        $englishKeys = $this->flattenKeys($englishData);
        $englishKeys = array_unique($englishKeys);
        sort($englishKeys);

        // Supported locales (excluding English which is the reference)
        $supportedLocales = ['fr', 'es', 'nl', 'ru', 'el', 'af', 'vi'];

        $missingKeysByLocale = [];

        foreach ($supportedLocales as $locale) {
            $translationFile = $this->translationsPath . "/messages.{$locale}.yaml";

            if (! file_exists($translationFile)) {
                $missingKeysByLocale[$locale] = ['file_missing' => true];

                continue;
            }

            $translationData = Yaml::parseFile($translationFile);

            if (! is_array($translationData)) {
                $missingKeysByLocale[$locale] = ['invalid_yaml' => true];

                continue;
            }

            $translationKeys = $this->flattenKeys($translationData);
            $translationKeys = array_unique($translationKeys);
            sort($translationKeys);

            // Find missing keys (keys in English but not in this translation)
            $missingKeys = array_diff($englishKeys, $translationKeys);

            // Find extra keys (keys in this translation but not in English)
            $extraKeys = array_diff($translationKeys, $englishKeys);

            if (! empty($missingKeys) || ! empty($extraKeys)) {
                $missingKeysByLocale[$locale] = [
                    'missing_keys' => array_values($missingKeys),
                    'extra_keys' => array_values($extraKeys),
                ];
            }
        }

        // Build error message if there are any issues
        if (! empty($missingKeysByLocale)) {
            $errorMessage = "Translation files have inconsistent keys. English (en) is the reference.\n\n";

            foreach ($missingKeysByLocale as $locale => $issues) {
                if (isset($issues['file_missing'])) {
                    $errorMessage .= "❌ {$locale}: Translation file is missing\n";
                } elseif (isset($issues['invalid_yaml'])) {
                    $errorMessage .= "❌ {$locale}: Translation file is invalid YAML\n";
                } else {
                    $errorMessage .= "❌ {$locale}:\n";
                    if (! empty($issues['missing_keys'])) {
                        $errorMessage .= "  Missing keys (" . count($issues['missing_keys']) . "):\n";
                        foreach ($issues['missing_keys'] as $key) {
                            $errorMessage .= "    - {$key}\n";
                        }
                    }
                    if (! empty($issues['extra_keys'])) {
                        $errorMessage .= "  Extra keys (" . count($issues['extra_keys']) . ") not in English:\n";
                        foreach ($issues['extra_keys'] as $key) {
                            $errorMessage .= "    - {$key}\n";
                        }
                    }
                }
            }

            $this->fail($errorMessage);
        }

        // If we get here, all translation files have the same keys
        $this->assertTrue(true);
    }
}
