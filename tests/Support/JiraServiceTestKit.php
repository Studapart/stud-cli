<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\CanConvertToPlainTextInterface;
use App\Service\JiraFieldMetadataService;
use App\Service\JiraIssueMapper;
use App\Service\JiraService;
use App\Service\JiraUserSearchService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class JiraServiceTestKit
{
    public static function create(HttpClientInterface $client, CanConvertToPlainTextInterface $htmlConverter): JiraService
    {
        return new JiraService(
            $client,
            new JiraIssueMapper($htmlConverter),
            new JiraFieldMetadataService($client),
            new JiraUserSearchService($client),
        );
    }
}
