<?php

declare(strict_types=1);

namespace App\Response;

final class BranchSwitchResponse extends AbstractResponse
{
    /**
     * @param array<string> $matches Matching local branches
     */
    private function __construct(
        bool $success,
        ?string $error,
        public readonly string $key,
        public readonly ?string $branch,
        public readonly array $matches,
        public readonly bool $switched,
        public readonly bool $needsSelection,
        public readonly bool $synced,
        public readonly ?int $syncExitCode,
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param array<string> $matches
     */
    public static function needsSelection(string $key, array $matches): self
    {
        return new self(false, null, $key, null, $matches, false, true, false, null);
    }

    public static function switched(string $key, string $branch): self
    {
        return new self(true, null, $key, $branch, [], true, false, false, null);
    }

    /**
     * @param array<string> $matches
     */
    public static function error(string $key, string $error, array $matches = [], ?string $branch = null, bool $switched = false): self
    {
        return new self(false, $error, $key, $branch, $matches, $switched, false, false, null);
    }

    public function withSyncResult(int $exitCode, string $error): self
    {
        if ($exitCode !== 0) {
            return new self(false, $error, $this->key, $this->branch, $this->matches, $this->switched, false, false, $exitCode);
        }

        return new self(true, null, $this->key, $this->branch, $this->matches, $this->switched, false, true, $exitCode);
    }
}
