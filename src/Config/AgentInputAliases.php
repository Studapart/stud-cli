<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Maps deprecated agent JSON property names to canonical keys (read path only).
 */
final class AgentInputAliases
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        foreach (self::ALIASES as $legacy => $canonical) {
            if (array_key_exists($legacy, $input) && ! array_key_exists($canonical, $input)) {
                $input[$canonical] = $input[$legacy];
            }
            unset($input[$legacy]);
        }

        return $input;
    }

    /** @var array<string, string> */
    private const ALIASES = [
        'workItemProviders' => 'issueTrackerProviders',
        'workItemProvider' => 'issueTrackerProvider',
    ];
}
