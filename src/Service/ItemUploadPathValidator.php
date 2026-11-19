<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;

/**
 * Decides whether a relative path given to item:upload names an existing regular file without traversal.
 */
class ItemUploadPathValidator
{
    public function __construct(
        private readonly FileSystem $fileSystem,
    ) {
    }

    /**
     * @return MessageRef|null the rejection reason, or null when the path can be uploaded
     */
    public function rejectionFor(string $path): ?MessageRef
    {
        if ($this->hasTraversalSegment($path)) {
            return MessageRef::key('item.upload.error_path_traversal');
        }

        if (! $this->fileSystem->fileExists($path)) {
            return MessageRef::key('item.upload.error_not_found');
        }

        if ($this->fileSystem->isDir($path)) {
            return MessageRef::key('item.upload.error_not_file');
        }

        return null;
    }

    protected function hasTraversalSegment(string $path): bool
    {
        return in_array('..', explode('/', str_replace('\\', '/', $path)), true);
    }
}
