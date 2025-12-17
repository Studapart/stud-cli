<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class CacheClearHandler
{
    private const CACHE_FILE_PATH = '~/.cache/stud/last_update_check.json';

    public function __construct(
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('cache.clear.section'));

        $cachePath = $this->getCachePath();

        if (! file_exists($cachePath)) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('cache.clear.already_clear'));

            return 0;
        }

        if (@unlink($cachePath)) {
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('cache.clear.success'));

            return 0;
        }

        // @codeCoverageIgnoreStart
        // unlink() failure is extremely rare and difficult to simulate in tests
        $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('cache.clear.error_delete'));

        return 1;
        // @codeCoverageIgnoreEnd
    }

    protected function getCachePath(): string
    {
        $home = $_SERVER['HOME'] ?? throw new \RuntimeException('Could not determine home directory.');

        return str_replace('~', $home, self::CACHE_FILE_PATH);
    }
}
