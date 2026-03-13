<?php

namespace App\Tests;

use App\Service\ConfluenceService;
use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\ProcessFactory;
use App\Service\TranslationService;

class TestKernel
{
    public static ?ConfluenceService $confluenceService = null;
    public static ?GitBranchService $gitBranchService = null;
    public static ?GitRepository $gitRepository = null;
    public static ?JiraService $jiraService = null;
    public static ?ProcessFactory $processFactory = null;
    public static ?TranslationService $translationService = null;
}
