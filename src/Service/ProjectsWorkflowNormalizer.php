<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Maps provider-specific workflow payloads to the unified projects:workflow shape.
 */
final class ProjectsWorkflowNormalizer
{
    /**
     * @param array<int, array{id: int|string, name: string, to?: array{name?: string}}> $transitions
     * @return list<array{id: string, name: string, targetStatus: string, provider: 'jira'}>
     */
    public function fromJiraTransitions(array $transitions): array
    {
        $workflows = [];
        foreach ($transitions as $transition) {
            $workflows[] = [
                'id' => (string) $transition['id'],
                'name' => (string) $transition['name'],
                'targetStatus' => (string) ($transition['to']['name'] ?? ''),
                'provider' => 'jira',
            ];
        }

        return $workflows;
    }

    /**
     * @param list<array{id: string, name: string, type: string}> $states
     * @return list<array{id: string, name: string, type: string, provider: 'linear'}>
     */
    public function fromLinearStates(array $states): array
    {
        $workflows = [];
        foreach ($states as $state) {
            $workflows[] = [
                'id' => $state['id'],
                'name' => $state['name'],
                'type' => $state['type'],
                'provider' => 'linear',
            ];
        }

        return $workflows;
    }
}
