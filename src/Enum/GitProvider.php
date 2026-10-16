<?php

declare(strict_types=1);

namespace App\Enum;

enum GitProvider: string
{
    case Github = 'github';
    case Gitlab = 'gitlab';

    public static function tryFromProjectValue(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    public function integrationKey(): string
    {
        return $this->value;
    }
}
