<?php

declare(strict_types=1);

namespace App\Service;

class FieldsParser
{
    public function __construct(
        private readonly DurationParser $durationParser
    ) {
    }

    /**
     * Parse a CLI --fields string into a raw key=>value map.
     * Format: key=value;key=value,value2
     *
     * @return array<string, string|list<string>>
     */
    public function parse(string $fieldsString): array
    {
        $fieldsString = trim($fieldsString);
        if ($fieldsString === '') {
            return [];
        }

        $result = [];
        $pairs = explode(';', $fieldsString);

        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $eqPos = strpos($pair, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = trim(substr($pair, 0, $eqPos));
            $value = substr($pair, $eqPos + 1);
            if ($key === '') {
                continue;
            }
            if (str_contains($value, ',')) {
                $result[$key] = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v) => $v !== ''));
            } else {
                $result[$key] = trim($value);
            }
        }

        return $result;
    }

    /**
     * Match parsed fields against Jira field metadata, apply transformations,
     * and return the payload-ready fields plus a list of unmatched keys.
     *
     * @param array<string, string|list<string>> $parsedFields From parse() or agent mode JSON
     * @param array<string, array{required: bool, name: string}> $fieldsMeta From createmeta or editmeta
     * @return array{matched: array<string, mixed>, unmatched: list<string>}
     */
    public function matchAndTransform(array $parsedFields, array $fieldsMeta): array
    {
        $matched = [];
        $unmatched = [];

        foreach ($parsedFields as $inputKey => $value) {
            $resolvedId = $this->resolveFieldId((string) $inputKey, $fieldsMeta);
            if ($resolvedId === null) {
                $unmatched[] = $inputKey;

                continue;
            }
            $payloadKey = $this->toPayloadKey($resolvedId);
            $matched[$payloadKey] = $this->transformValue($resolvedId, $value);
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * Resolve user-supplied key to a Jira field ID by matching against metadata.
     * Matches by exact field ID (case-insensitive) then by field name (case-insensitive).
     *
     * @param array<string, array{required: bool, name: string}> $fieldsMeta
     */
    protected function resolveFieldId(string $inputKey, array $fieldsMeta): ?string
    {
        $inputLower = strtolower($inputKey);

        foreach ($fieldsMeta as $fieldId => $meta) {
            if (strtolower((string) $fieldId) === $inputLower) {
                return (string) $fieldId;
            }
        }

        foreach ($fieldsMeta as $fieldId => $meta) {
            if (strtolower($meta['name']) === $inputLower) {
                return (string) $fieldId;
            }
        }

        return null;
    }

    /**
     * Returns the payload key for a Jira field ID.
     * Numeric IDs are prefixed with "customfield_".
     */
    protected function toPayloadKey(string $fieldId): string
    {
        if (str_starts_with($fieldId, 'customfield_')) {
            return $fieldId;
        }
        if (preg_match('/^\d+$/', $fieldId)) {
            return 'customfield_' . $fieldId;
        }

        return $fieldId;
    }

    /** @var array<string, string> */
    private const TRANSFORM_MAP = [
        'labels' => 'array',
        'timeoriginalestimate' => 'duration',
        'timetracking' => 'duration',
        'parent' => 'key_wrap',
        'assignee' => 'id_wrap',
        'reporter' => 'id_wrap',
        'priority' => 'name_wrap',
        'fixversions' => 'versions',
        'versions' => 'versions',
    ];

    /**
     * Apply known transformations for well-known Jira field types.
     *
     * @param string|list<string> $value
     * @return mixed
     */
    protected function transformValue(string $fieldId, string|array $value): mixed
    {
        $kind = self::TRANSFORM_MAP[strtolower($fieldId)] ?? null;

        return match ($kind) {
            'array' => is_array($value) ? $value : [$value],
            'duration' => $this->transformDuration($value),
            'key_wrap' => ['key' => $this->scalar($value)],
            'id_wrap' => ['id' => $this->scalar($value)],
            'name_wrap' => ['name' => $this->scalar($value)],
            'versions' => $this->transformVersions($value),
            default => $value,
        };
    }

    /**
     * @param string|list<string> $value
     */
    private function scalar(string|array $value): string
    {
        return is_array($value) ? $value[0] : $value;
    }

    /**
     * @param string|list<string> $value
     * @return int|string|list<string>
     */
    protected function transformDuration(string|array $value): int|string|array
    {
        $raw = is_array($value) ? $value[0] : $value;
        $seconds = $this->durationParser->parseToSeconds($raw);

        return $seconds ?? $raw;
    }

    /**
     * @param string|list<string> $value
     * @return list<array{name: string}>
     */
    protected function transformVersions(string|array $value): array
    {
        $items = is_array($value) ? $value : [$value];

        return array_map(static fn (string $v) => ['name' => $v], $items);
    }
}
