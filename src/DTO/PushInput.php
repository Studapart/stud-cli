<?php

declare(strict_types=1);

namespace App\DTO;

final class PushInput
{
    /**
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function __construct(
        public readonly bool $isNew = false,
        public readonly ?string $message = null,
        public readonly bool $stageAll = false,
        public readonly bool $quiet = false,
        public readonly bool $noPlease = false,
        public readonly bool $agentMode = false,
        public readonly bool $pleaseFallback = false,
        public readonly bool $flatten = false,
    ) {
    }
}
