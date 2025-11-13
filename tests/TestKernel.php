<?php

namespace App\Tests;

use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\ProcessFactory;
use App\Service\TranslationService;

class TestKernel
{
    public static ?GitRepository $gitRepository = null;
    public static ?JiraService $jiraService = null;
    public static ?ProcessFactory $processFactory = null;
    public static ?TranslationService $translationService = null;
}
