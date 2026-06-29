<?php

declare(strict_types=1);

namespace App\Exception;

use App\DTO\MessageRef;

final class LinearTypeLabelException extends \RuntimeException
{
    public function __construct(
        public readonly MessageRef $messageRef,
    ) {
        parent::__construct((string) $messageRef);
    }

    public static function groupNotConfigured(): self
    {
        return new self(MessageRef::key('item.create.linear_type_group_not_configured'));
    }

    public static function labelNotFound(string $typeName): self
    {
        return new self(MessageRef::key('item.create.linear_type_label_not_found', ['type' => $typeName]));
    }

    public static function resolverUnavailable(): self
    {
        return new self(MessageRef::key('item.create.linear_type_resolver_unavailable'));
    }
}
