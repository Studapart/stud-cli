<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Aggregate interface for HTML converters that support all formats.
 * This is a convenience interface for converters that implement all conversion methods.
 */
interface HtmlConverterInterface extends
    CanConvertToPlainTextInterface,
    CanConvertToMarkdownInterface,
    CanConvertToAsciiDocInterface
{
    // Empty - just aggregates the individual interfaces
}
