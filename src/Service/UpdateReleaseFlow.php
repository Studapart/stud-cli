<?php

declare(strict_types=1);

namespace App\Service;

final class UpdateReleaseFlow
{
    public function __construct(
        public readonly UpdateReleaseFetcher $fetcher,
        public readonly UpdateChangelogPresenter $changelogPresenter,
    ) {
    }
}
