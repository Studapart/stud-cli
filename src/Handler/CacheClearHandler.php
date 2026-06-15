<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Response\CommandResponse;
use App\Service\FileSystem;

class CacheClearHandler
{
    private const CACHE_FILE_PATH = '~/.cache/stud/last_update_check.json';

    public function __construct(
        mixed $_translator,
        private readonly FileSystem $fileSystem,
    ) {
        unset($_translator);
    }

    public function handle(): CommandResponse
    {
        $cachePath = $this->getCachePath();

        if (! $this->fileSystem->fileExists($cachePath)) {
            return CommandResponse::success(
                messages: [ResponseMessage::notice(MessageRef::key('cache.clear.already_clear'))],
            );
        }

        try {
            $this->fileSystem->delete($cachePath);

            return CommandResponse::success(MessageRef::key('cache.clear.success'));
        } catch (\RuntimeException $e) {
            $error = MessageRef::key('cache.clear.error_delete');

            return CommandResponse::error(
                $error,
                [ResponseMessage::error($error, $e->getMessage())],
            );
        }
    }

    protected function getCachePath(): string
    {
        $home = $_SERVER['HOME'] ?? throw new \RuntimeException('Could not determine home directory.');

        return str_replace('~', $home, self::CACHE_FILE_PATH);
    }
}
