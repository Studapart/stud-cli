<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\ConfigValidateIntegrationPing;
use App\DTO\MessageRef;
use App\Guard\DTO\ProviderResolutionResult;
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

        $result = $this->evaluator->evaluate(
            $integrations,
            ProviderResolutionResult::single(['jira'], 'test'),
            false,
            false,
            null,
        );

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
            ProviderResolutionResult::dualAggregate(['jira', 'linear'], 'test'),
            false,
            false,
            null,
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

        $result = $this->evaluator->evaluate(
            $integrations,
            ProviderResolutionResult::single(['jira'], 'test'),
            true,
            true,
            'github',
        );

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_PARTIAL, $result->globalRollup);
        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_OK, $result->projectRollup);
        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->notices);
    }

    public function testProjectFailWhenSelectedTrackerFails(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_FAIL),
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate(
            $integrations,
            ProviderResolutionResult::single(['jira'], 'test'),
            true,
            true,
            'github',
        );

        $this->assertFalse($result->success);
        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_FAIL, $result->projectRollup);
    }

    public function testProjectFailWhenResolutionBlocked(): void
    {
        $integrations = [
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate(
            $integrations,
            ProviderResolutionResult::blocked(MessageRef::key('guard.error.ambiguous_issue_tracker_provider')),
            true,
            true,
            'github',
        );

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
            ProviderResolutionResult::single(['jira'], 'test'),
            true,
            true,
            'gitlab',
        );

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_FAIL, $result->projectRollup);
    }

    public function testProjectFailWhenSelectedTrackerNotOk(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_SKIPPED),
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate(
            $integrations,
            ProviderResolutionResult::single(['jira'], 'test'),
            true,
            true,
            'github',
        );

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_FAIL, $result->projectRollup);
    }

    public function testProjectOkWhenGitProviderUnset(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
        ];

        $result = $this->evaluator->evaluate(
            $integrations,
            ProviderResolutionResult::single(['jira'], 'test'),
            true,
            true,
            null,
        );

        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_OK, $result->projectRollup);
    }

    public function testProjectFailWhenProjectGitFails(): void
    {
        $integrations = [
            'jira' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_OK),
            'github' => new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_FAIL),
        ];

        $result = $this->evaluator->evaluate(
            $integrations,
            ProviderResolutionResult::single(['jira'], 'test'),
            true,
            true,
            'github',
        );

        $this->assertFalse($result->success);
        $this->assertSame(ConfigReadinessEvaluator::ROLLUP_FAIL, $result->projectRollup);
    }
}
