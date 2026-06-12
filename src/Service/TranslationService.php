<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

class TranslationService
{
    private const AGENT_DOMAIN = 'agent';
    private const AGENT_LOCALE = 'en';

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

        $agentFile = $resolvedPath . '/messages.agent.en.yaml';
        if (file_exists($agentFile)) {
            $this->translator->addResource('yaml', $agentFile, self::AGENT_LOCALE, self::AGENT_DOMAIN);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->translator->trans($id, $this->formatParameters($parameters, $domain, $locale), $domain, $locale);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function transForAgent(string $id, array $parameters = []): string
    {
        $agentTranslation = $this->translator->trans(
            $id,
            $this->formatParametersForAgent($parameters),
            self::AGENT_DOMAIN,
            self::AGENT_LOCALE,
        );
        if ($agentTranslation !== $id) {
            return $agentTranslation;
        }

        return $this->translator->trans(
            $id,
            $this->formatParametersForAgent($parameters),
            locale: self::AGENT_LOCALE,
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function transForAgentText(string $id, array $parameters = []): string
    {
        $translated = $this->transForAgent($id, $parameters);

        return $translated !== '' ? $translated : $id;
    }

    public function render(MessageRef|string|null $message, ?string $domain = null, ?string $locale = null): ?string
    {
        if ($message === null) {
            return null;
        }

        if (is_string($message)) {
            return $message;
        }

        $translated = $this->trans($message->key, $message->parameters, $domain, $locale);

        return $translated === $message->key && $message->fallback !== null ? $message->fallback : $translated;
    }

    public function renderText(MessageRef|string|null $message, ?string $domain = null, ?string $locale = null): string
    {
        return $this->render($message, $domain, $locale) ?? ($message === null ? '' : (string) $message);
    }

    public function renderForAgent(MessageRef|string|null $message): ?string
    {
        if ($message === null) {
            return null;
        }

        if (is_string($message)) {
            return $message;
        }

        $translated = $this->transForAgent($message->key, $message->parameters);

        return $translated === $message->key && $message->fallback !== null ? $message->fallback : $translated;
    }

    public function renderForAgentText(MessageRef|string|null $message): string
    {
        $rendered = $this->renderForAgent($message);

        return $rendered === null || $rendered === '' ? ($message === null ? '' : (string) $message) : $rendered;
    }

    public function getLocale(): string
    {
        return $this->translator->getLocale();
    }

    /**
     * Symfony Translator expects parameter keys to include % signs.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function formatParameters(array $parameters, ?string $domain = null, ?string $locale = null): array
    {
        $formattedParameters = [];
        foreach ($parameters as $key => $value) {
            $formattedParameters[$this->formatParameterKey((string) $key)] = $value instanceof MessageRef
                ? $this->render($value, $domain, $locale)
                : $value;
        }

        return $formattedParameters;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function formatParametersForAgent(array $parameters): array
    {
        $formattedParameters = [];
        foreach ($parameters as $key => $value) {
            $formattedParameters[$this->formatParameterKey((string) $key)] = $value instanceof MessageRef
                ? $this->renderForAgent($value)
                : $value;
        }

        return $formattedParameters;
    }

    private function formatParameterKey(string $key): string
    {
        if (! str_starts_with($key, '%')) {
            $key = '%' . $key;
        }
        if (! str_ends_with($key, '%')) {
            $key .= '%';
        }

        return $key;
    }
}
