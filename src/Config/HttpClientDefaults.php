<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Shared Symfony HttpClient limits: chatty APIs vs bulk file transfers.
 */
final class HttpClientDefaults
{
    public const TIMEOUT_SECONDS = 30.0;
    public const MAX_DURATION_SECONDS = 60.0;

    /**
     * Merge default timeout/duration onto client options. Explicit keys win.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function withLimits(array $options = []): array
    {
        return $options + [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::MAX_DURATION_SECONDS,
        ];
    }

    /**
     * Idle timeout plus unlimited total duration, for large file transfers.
     *
     * Explicit `max_duration` of 0 overrides a client built with {@see withLimits()}
     * so request-level merge does not keep the 60s API cap.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function withTransferLimits(array $options = []): array
    {
        return $options + [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => 0.0,
        ];
    }
}
