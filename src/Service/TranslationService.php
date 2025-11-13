<?php

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
        
        // Load translation files from the specified path
        // Use opendir/readdir for PHAR compatibility (glob doesn't always work with phar://)
        $supportedLocales = ['en', 'fr', 'es', 'nl', 'ru', 'el', 'af', 'vi'];
        
        foreach ($supportedLocales as $fileLocale) {
            $file = $translationsPath . '/messages.' . $fileLocale . '.yaml';
            if (file_exists($file)) {
                $this->translator->addResource('yaml', $file, $fileLocale);
            }
        }
    }

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        // Symfony Translator expects parameter keys to include % signs
        $formattedParameters = [];
        foreach ($parameters as $key => $value) {
            $formattedKey = $key;
            if (!str_starts_with($formattedKey, '%')) {
                $formattedKey = '%' . $formattedKey;
            }
            if (!str_ends_with($formattedKey, '%')) {
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

