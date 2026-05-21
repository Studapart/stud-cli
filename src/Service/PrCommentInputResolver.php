<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PrCommentRequest;

class PrCommentInputResolver
{
    public function resolve(?string $message, ?string $replyTo = null, bool $resolve = false): PrCommentRequest
    {
        return new PrCommentRequest($this->commentBody($message), $replyTo, $resolve);
    }

    protected function commentBody(?string $message): ?string
    {
        $stdinContent = $this->readStdin();
        if ($stdinContent !== '') {
            return $stdinContent;
        }

        return $message;
    }

    /**
     * Reads content from STDIN if available without blocking interactive sessions.
     */
    protected function readStdin(): string
    {
        // @codeCoverageIgnoreStart
        if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
            return '';
        }

        if (is_resource(STDIN)) {
            $metaData = stream_get_meta_data(STDIN);
            $wasBlocking = $metaData['blocked'];
            stream_set_blocking(STDIN, false);
            $content = stream_get_contents(STDIN);
            stream_set_blocking(STDIN, $wasBlocking);

            return $content !== false ? trim($content) : '';
        }

        if (! function_exists('posix_isatty') || ! posix_isatty(STDIN)) {
            $content = @file_get_contents('php://stdin');

            return $content !== false ? trim($content) : '';
        }

        return '';
        // @codeCoverageIgnoreEnd
    }
}
