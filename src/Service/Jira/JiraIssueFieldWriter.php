<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Service\FieldsParser;
use App\Service\IssueTrackerPort;

final class JiraIssueFieldWriter
{
    public function __construct(
        private readonly FieldsParser $fieldsParser,
    ) {
    }

    /**
     * @param array<string, string|list<string>> $parsedFields
     * @param array<string, array{required: bool, name: string}> $fieldsMeta
     *
     * @return array{matched: array<string, mixed>, unmatched: list<string>}
     */
    public function matchAgainstMeta(array $parsedFields, array $fieldsMeta): array
    {
        $result = $this->fieldsParser->matchAndTransform($parsedFields, $fieldsMeta);

        return [
            'matched' => $result['matched'],
            'unmatched' => $result['unmatched'],
        ];
    }

    /**
     * @param array<string, string|list<string>> $parsedFields
     * @param array<string, mixed> $rawFields values already shaped for Jira
     *
     * @return list<string> human-readable fields that were not applied
     */
    public function applyFields(string $issueKey, array $parsedFields, IssueTrackerPort $port, array $rawFields = []): array
    {
        if ($parsedFields === [] && $rawFields === []) {
            return [];
        }

        try {
            $meta = $port->getEditMetaFields($issueKey);
        } catch (\Throwable) {
            return array_map(static fn (string|int $key): string => (string) $key, array_keys($parsedFields + $rawFields));
        }

        $matched = $this->payloadFor($parsedFields, $rawFields, $meta);
        if ($matched['fields'] === []) {
            return $matched['warnings'];
        }

        try {
            $port->update($issueKey, $matched['fields']);
        } catch (\Throwable) {
            return array_merge($matched['warnings'], $this->namesFor(array_keys($matched['fields']), $meta));
        }

        return $matched['warnings'];
    }

    /**
     * @param array<string, string|list<string>> $parsedFields
     * @param array<string, mixed> $rawFields
     * @param array<string, array{required: bool, name: string}> $meta
     *
     * @return array{fields: array<string, mixed>, warnings: list<string>}
     */
    private function payloadFor(array $parsedFields, array $rawFields, array $meta): array
    {
        $matched = $parsedFields === []
            ? ['matched' => [], 'unmatched' => []]
            : $this->matchAgainstMeta($parsedFields, $meta);
        $warnings = $this->namesFor($matched['unmatched'], $meta);
        foreach ($rawFields as $key => $value) {
            if (! isset($meta[$key])) {
                $warnings[] = $key;

                continue;
            }
            $matched['matched'][$key] = $value;
        }

        return ['fields' => $matched['matched'], 'warnings' => $warnings];
    }

    /**
     * @param list<string> $keys
     * @param array<string, array{required: bool, name: string}> $meta
     *
     * @return list<string>
     */
    private function namesFor(array $keys, array $meta): array
    {
        $names = [];
        foreach ($keys as $key) {
            $names[] = (string) ($meta[$key]['name'] ?? $key);
        }

        return $names;
    }
}
