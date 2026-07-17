<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\ProjectStudConfigKeys;
use App\DTO\MessageRef;
use App\Exception\LinearTypeLabelException;

/**
 * Maps Linear LabelGroup type labels to git branch prefixes and create-time label ids.
 */
final class LinearTypeLabelResolver
{
    /** @var list<string>|null */
    private ?array $cachedTypeLabelNames = null;

    public function __construct(
        private readonly ?LinearApiClient $linearApiClient = null,
    ) {
    }

    /**
     * @param list<string> $issueLabelNames
     * @param array<string, mixed> $projectConfig
     *
     * @return array{prefix: string, matchedLabel: ?string, warning: ?MessageRef}
     */
    public function resolveBranchPrefix(array $issueLabelNames, array $projectConfig, ?string $teamKey = null): array
    {
        $groupId = $this->readTypeGroupId($projectConfig);
        if ($groupId === null) {
            return [
                'prefix' => BranchNameGenerator::PREFIX_FEAT,
                'matchedLabel' => null,
                'warning' => null,
            ];
        }

        $prefixMap = $this->readBranchPrefixMap($projectConfig);
        $typeLabelNames = $this->resolveTypeLabelNames($projectConfig, $teamKey, $groupId);
        $matchedLabel = $this->findMatchingTypeLabel($issueLabelNames, $typeLabelNames);

        if ($matchedLabel === null) {
            return [
                'prefix' => BranchNameGenerator::PREFIX_FEAT,
                'matchedLabel' => null,
                'warning' => MessageRef::key('item.start.linear_no_type_label'),
            ];
        }

        $prefix = $this->mapLabelNameToPrefix($matchedLabel, $prefixMap);
        if ($prefix === null) {
            return [
                'prefix' => BranchNameGenerator::PREFIX_FEAT,
                'matchedLabel' => $matchedLabel,
                'warning' => MessageRef::key('item.start.linear_unknown_type_label', ['label' => $matchedLabel]),
            ];
        }

        return [
            'prefix' => $prefix,
            'matchedLabel' => $matchedLabel,
            'warning' => null,
        ];
    }

    /**
     * @param array<string, mixed> $projectConfig
     *
     * @throws LinearTypeLabelException
     */
    public function resolveTypeLabelId(string $typeName, array $projectConfig, string $teamKey): string
    {
        $groupId = $this->readTypeGroupId($projectConfig);
        if ($groupId === null) {
            throw LinearTypeLabelException::groupNotConfigured();
        }

        if ($this->linearApiClient === null) {
            throw LinearTypeLabelException::resolverUnavailable();
        }

        $labelIds = $this->linearApiClient->resolveLabelIds($teamKey, [$typeName], $groupId);
        if ($labelIds === []) {
            throw LinearTypeLabelException::labelNotFound($typeName);
        }

        return $labelIds[0];
    }

    /**
     * @param array<string, mixed> $projectConfig
     *
     * @return list<string>
     */
    protected function resolveTypeLabelNames(array $projectConfig, ?string $teamKey, string $groupId): array
    {
        if ($this->cachedTypeLabelNames !== null) {
            return $this->cachedTypeLabelNames;
        }

        if ($teamKey !== null && trim($teamKey) !== '' && $this->linearApiClient !== null) {
            foreach ($this->linearApiClient->getTeamLabelGroups($teamKey, false) as $group) {
                if ($group['id'] !== $groupId) {
                    continue;
                }

                $names = [];
                foreach ($group['labels'] as $label) {
                    $name = trim($label['name']);
                    if ($name !== '') {
                        $names[] = $name;
                    }
                }

                $this->cachedTypeLabelNames = $names;

                return $names;
            }
        }

        $this->cachedTypeLabelNames = array_keys($this->readBranchPrefixMap($projectConfig));

        return $this->cachedTypeLabelNames;
    }

    /**
     * @param list<string> $issueLabelNames
     * @param list<string> $typeLabelNames
     */
    protected function findMatchingTypeLabel(array $issueLabelNames, array $typeLabelNames): ?string
    {
        $typeIndex = [];
        foreach ($typeLabelNames as $name) {
            $typeIndex[strtolower(trim($name))] = $name;
        }

        foreach ($issueLabelNames as $issueLabel) {
            $normalized = strtolower(trim($issueLabel));
            if ($normalized === '') {
                continue;
            }

            if (isset($typeIndex[$normalized])) {
                return $typeIndex[$normalized];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $prefixMap
     */
    protected function mapLabelNameToPrefix(string $labelName, array $prefixMap): ?string
    {
        foreach ($prefixMap as $configuredLabel => $prefix) {
            if (strcasecmp(trim($configuredLabel), trim($labelName)) === 0) {
                $normalized = trim($prefix);

                return $normalized === '' ? null : $normalized;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    protected function readTypeGroupId(array $projectConfig): ?string
    {
        $value = $projectConfig[ProjectStudConfigKeys::LINEAR_TYPE_LABEL_GROUP_ID] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $projectConfig
     *
     * @return array<string, string>
     */
    protected function readBranchPrefixMap(array $projectConfig): array
    {
        $raw = $projectConfig[ProjectStudConfigKeys::LINEAR_TYPE_BRANCH_PREFIXES] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $label => $prefix) {
            if (is_string($label) && is_string($prefix) && trim($label) !== '' && trim($prefix) !== '') {
                $map[$label] = trim($prefix);
            }
        }

        return $map;
    }
}
