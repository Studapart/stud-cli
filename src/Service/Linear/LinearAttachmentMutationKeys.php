<?php

declare(strict_types=1);

namespace App\Service\Linear;

/**
 * Linear GraphQL attachment write input field names (fileUpload variables, attachmentCreate input).
 *
 * Response parsing keys stay inline in {@see \App\Service\LinearApiClient} until SCI-192 query vocabulary.
 */
final class LinearAttachmentMutationKeys
{
    public const FILENAME = 'filename';

    public const CONTENT_TYPE = 'contentType';

    public const SIZE = 'size';

    public const ISSUE_ID = 'issueId';

    public const URL = 'url';
}
