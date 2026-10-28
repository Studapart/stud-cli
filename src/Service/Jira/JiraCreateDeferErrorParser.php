<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Exception\ApiException;

final class JiraCreateDeferErrorParser
{
    /**
     * @return array<string, string>|null field id => message when every error is deferrable
     */
    public function parseDeferrableFieldErrors(ApiException $exception): ?array
    {
        if ($exception->getStatusCode() !== 400) {
            return null;
        }

        $decoded = $this->decodeBody($exception->getTechnicalDetails());
        if (! is_array($decoded) || ! isset($decoded['errors']) || ! is_array($decoded['errors'])) {
            return null;
        }

        return $this->collectDeferrable($decoded['errors']);
    }

    private function decodeBody(string $technicalDetails): mixed
    {
        $body = preg_replace('/^HTTP \d+:\s*/', '', $technicalDetails) ?? $technicalDetails;
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<mixed> $errors
     *
     * @return array<string, string>|null
     */
    private function collectDeferrable(array $errors): ?array
    {
        $deferrable = [];
        foreach ($errors as $field => $message) {
            if (! is_string($field) || ! is_string($message) || ! $this->isDeferrableMessage($message)) {
                return null;
            }
            $deferrable[$field] = $message;
        }

        return $deferrable === [] ? null : $deferrable;
    }

    private function isDeferrableMessage(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'cannot be set')
            || str_contains($lower, 'not on the appropriate screen');
    }
}
