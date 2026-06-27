<?php

declare(strict_types=1);

namespace App\Service\Linear;

/**
 * Linear GraphQL issueCreate / issueUpdate input field names (protocol vocabulary).
 */
final class LinearIssueMutationKeys
{
    public const TEAM_ID = 'teamId';

    public const TITLE = 'title';

    public const DESCRIPTION = 'description';

    public const LABEL_IDS = 'labelIds';

    public const PRIORITY = 'priority';

    public const PARENT_ID = 'parentId';
}
