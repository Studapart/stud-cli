<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class CacheClearHandler
{
    private const CACHE_FILE_PATH = '~/.cache/stud/last_update_check.json';

    public function __construct(
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        $io->section($this->translator->trans('cache.clear.section'));

        $cachePath = $this->getCachePath();

        if (! file_exists($cachePath)) {
            $io->note($this->translator->trans('cache.clear.already_clear'));

            return 0;
        }

        if (@unlink($cachePath)) {
            $io->success($this->translator->trans('cache.clear.success'));

            return 0;
        }

        // @codeCoverageIgnoreStart
        // unlink() failure is extremely rare and difficult to simulate in tests
        $io->error($this->translator->trans('cache.clear.error_delete'));

        return 1;
        // @codeCoverageIgnoreEnd
    }

    protected function getCachePath(): string
    {
        $home = $_SERVER['HOME'] ?? throw new \RuntimeException('Could not determine home directory.');

        return str_replace('~', $home, self::CACHE_FILE_PATH);
    }
}
