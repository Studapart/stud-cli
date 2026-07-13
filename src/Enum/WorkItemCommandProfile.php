<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Work-item command semantics for unified provider resolution (SCI-197).
 */
enum WorkItemCommandProfile: string
{
    case DualAggregate = 'dual_aggregate';
    case KeySingle = 'key_single';
    case KeyOrBranch = 'key_or_branch';
    case ScopeDiscovery = 'scope_discovery';
    case FilterByName = 'filter_by_name';
    case SearchSingle = 'search_single';
    case LinearOnly = 'linear_only';
}
