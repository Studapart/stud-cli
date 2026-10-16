<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\ConfigValidateIntegrationPing;
use App\DTO\ConfigValidatePlan;
use App\DTO\MessageRef;
use App\Enum\GitProvider;
use App\Enum\IssueTrackerProvider;
use App\Response\ConfigValidateResponse;
use App\Service\ConfigReadinessEvaluator;
use PHPUnit\Framework\TestCase;

final class ConfigReadinessEvaluatorTest extends TestCase
{
    private ConfigReadinessEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ConfigReadinessEvaluator();
    }

    public function testGlobalOnlyFailWhenAnyExecutedCheckFails(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_FAIL),
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate($integrations, $this->plan(false, false, null));

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_PARTIAL, $result->globalRollup);
        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_NOT_APPLICABLE, $result->projectRollup);
        $this->assertTrue($result->success);
    }

    public function testGlobalPartialWhenMixedResults(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
            'linear' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_FAIL),
        ];

        $result = $this->evaluator->evaluate(
            $integrations,
            $this->plan(false, false, null, probeLinear: true, includeGithub: false),
        );

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_PARTIAL, $result->globalRollup);
        $this->assertTrue($result->success);
    }

    public function testProjectOkWhenSelectedTrackerOkDespiteOtherTrackerFail(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
            'linear' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_FAIL),
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate($integrations, $this->plan(probeLinear: true));

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_PARTIAL, $result->globalRollup);
        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_OK, $result->projectRollup);
        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->notices);
        $this->assertInstanceOf(MessageRef::class, $result->notices[0]);
        $this->assertSame('config.validate.notice_linear_non_blocking', $result->notices[0]->key);
    }

    public function testProjectNoticeUsesJiraKeyWhenJiraIsNotSelected(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_FAIL),
            'linear' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate(
            $integrations,
            $this->plan(selected: 'linear', probeLinear: true),
        );

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_OK, $result->projectRollup);
        $this->assertInstanceOf(MessageRef::class, $result->notices[0]);
        $this->assertSame('config.validate.notice_jira_non_blocking', $result->notices[0]->key);
    }

    public function testProjectFailWhenSelectedTrackerFails(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_FAIL),
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate($integrations, $this->plan());

        $this->assertFalse($result->success);
        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_FAIL, $result->projectRollup);
    }

    public function testProjectFailWhenResolutionBlocked(): void
    {
        $integrations = [
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate($integrations, $this->plan(selected: null, selectionBlocked: true));

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_FAIL, $result->projectRollup);
        $this->assertFalse($result->success);
    }

    public function testProjectFailWhenProjectGitSkipped(): void
    {
        $integrations = [
            'gitlab' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_SKIPPED),
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate(
            $integrations,
            $this->plan(git: 'gitlab', includeGithub: false, includeGitlab: true),
        );

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_FAIL, $result->projectRollup);
    }

    public function testProjectFailWhenSelectedTrackerNotOk(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_SKIPPED),
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate($integrations, $this->plan());

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_FAIL, $result->projectRollup);
    }

    public function testProjectFailWhenSelectedTrackerNotProbed(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_SKIPPED),
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate(
            $integrations,
            $this->plan(probeJira: false),
        );

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_FAIL, $result->projectRollup);
        $this->assertFalse($result->success);
    }

    public function testProjectFailWhenGitProviderUnset(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate($integrations, $this->plan(git: null));

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_FAIL, $result->projectRollup);
        $this->assertFalse($result->success);
    }

    public function testProjectFailWhenSelectedGitPingMissing(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate($integrations, $this->plan());

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_FAIL, $result->projectRollup);
    }

    public function testProjectFailWhenSelectedTrackerPingMissing(): void
    {
        $integrations = [
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate($integrations, $this->plan());

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_FAIL, $result->projectRollup);
    }

    public function testProjectOkWhenUserSkipsSelectedLinearProbe(): void
    {
        $integrations = [
            'linear' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_SKIPPED),
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];
        $plan = new ConfigValidatePlan(
            true,
            true,
            false,
            false,
            true,
            true,
            false,
            IssueTrackerProvider::Linear,
            null,
            GitProvider::Github,
            false,
            true,
        );

        $result = $this->evaluator->evaluate($integrations, $plan);

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_OK, $result->projectRollup);
        $this->assertTrue($result->success);
    }

    public function testProjectFailWhenProjectGitFails(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_FAIL),
        ];

        $result = $this->evaluator->evaluate($integrations, $this->plan());

        $this->assertFalse($result->success);
        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_FAIL, $result->projectRollup);
    }

    private function plan(
        bool $inRepo = true,
        bool $hasProject = true,
        ?string $git = 'github',
        ?string $selected = 'jira',
        bool $probeJira = true,
        bool $probeLinear = false,
        bool $includeGithub = true,
        bool $includeGitlab = false,
        bool $selectionBlocked = false,
    ): ConfigValidatePlan {
        return new ConfigValidatePlan(
            $inRepo,
            $hasProject,
            $probeJira,
            $probeLinear,
            $includeGithub || $includeGitlab,
            $includeGithub,
            $includeGitlab,
            $selectionBlocked || $selected === null ? null : IssueTrackerProvider::from($selected),
            $selectionBlocked ? MessageRef::key('guard.error.ambiguous_issue_tracker_provider') : null,
            GitProvider::tryFromProjectValue($git),
        );
    }
}
