<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

class TranslationService
{
    private Translator $translator;

    public function __construct(string $locale, string $translationsPath)
    {
        $this->translator = new Translator($locale);
        $this->translator->addLoader('yaml', new YamlFileLoader());

        // Resolve the translations path to an absolute path
        $resolvedPath = realpath($translationsPath) ?: $translationsPath;

        // Load translation files from the specified path
        // Use native file_exists for absolute paths since FileSystem uses getcwd() as root
        $supportedLocales = ['en', 'fr', 'es', 'nl', 'ru', 'el', 'af', 'vi'];

        foreach ($supportedLocales as $fileLocale) {
            $file = $resolvedPath . '/messages.' . $fileLocale . '.yaml';
            if (file_exists($file)) {
                $this->translator->addResource('yaml', $file, $fileLocale);
            }
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        // Symfony Translator expects parameter keys to include % signs
        $formattedParameters = [];
        foreach ($parameters as $key => $value) {
            $formattedKey = $key;
            if (! str_starts_with($formattedKey, '%')) {
                $formattedKey = '%' . $formattedKey;
            }
            if (! str_ends_with($formattedKey, '%')) {
                $formattedKey = $formattedKey . '%';
            }
            $formattedParameters[$formattedKey] = $value;
        }

        return $this->translator->trans($id, $formattedParameters, $domain, $locale);
    }

    public function getLocale(): string
    {
        return $this->translator->getLocale();
    }
}
