<?php

namespace App\Tests;

use App\Service\ConfluenceApiClient;
use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Service\IssueTrackerPort;
use App\Service\JiraApiClient;
use App\Service\JiraAttachmentService;
use App\Service\LinearGraphqlClient;
use App\Service\ProcessFactory;
use App\Service\TranslationService;

class TestKernel
{
    public static ?ConfluenceApiClient $confluenceApiClient = null;
    public static ?GitBranchService $gitBranchService = null;
    public static ?GitRepository $gitRepository = null;
    public static ?JiraAttachmentService $jiraAttachmentService = null;
    public static ?JiraApiClient $jiraApiClient = null;
    public static ?LinearGraphqlClient $linearGraphqlClient = null;
    public static ?ProcessFactory $processFactory = null;
    public static ?TranslationService $translationService = null;
    public static ?IssueTrackerPort $issueTracker = null;
}
