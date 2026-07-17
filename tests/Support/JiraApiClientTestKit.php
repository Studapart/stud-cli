<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\CanConvertToPlainTextInterface;
use App\Service\JiraApiClient;
use App\Service\JiraFieldMetadataService;
use App\Service\JiraIssueMapper;
use App\Service\JiraUserSearchService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class JiraApiClientTestKit
{
    public static function create(HttpClientInterface $client, CanConvertToPlainTextInterface $htmlConverter): JiraApiClient
    {
        return new JiraApiClient(
            $client,
            new JiraIssueMapper($htmlConverter),
            new JiraFieldMetadataService($client),
            new JiraUserSearchService($client),
        );
    }
}
