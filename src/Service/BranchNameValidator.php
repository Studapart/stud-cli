<?php

declare(strict_types=1);

namespace App\Service;

class BranchNameValidator
{
    public function validateBranchName(string $name): bool
    {
        if (preg_match('/^[a-zA-Z0-9._\/-]+$/', $name) === 0) {
            return false;
        }
        if (str_contains($name, '..')) {
            return false;
        }
        if (str_ends_with($name, '.lock')) {
            return false;
        }
        // @codeCoverageIgnoreStart
        if (str_contains($name, '@{')) {
            return false;
        }
        if (str_contains($name, '\\')) {
            return false;
        }
        if (preg_match('/\.\./', $name)) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        return true;
    }

    public function looksLikeJiraKey(string $value): bool
    {
        return (bool) preg_match('/^[A-Z]+-\d+$/', strtoupper($value));
    }
}
