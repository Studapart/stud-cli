<?php

namespace App\Tests\Responder;

use App\DTO\ConfigValidateIntegrationPing;
use App\DTO\ConfigValidatePingResults;
use App\DTO\MessageRef;
use App\Responder\ConfigValidateCliView;
use App\Response\ConfigValidateResponse;
use App\Service\ColorHelper;
use App\Service\ConfigReadinessEvaluation;
use App\Service\ConfigReadinessEvaluator;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;

class ConfigValidateCliViewTest extends CommandTestCase
{
    private ConfigValidateCliView $view;

    protected function setUp(): void
    {
        parent::setUp();
        $this->view = new ConfigValidateCliView(new ResponderHelper($this->translationService));
    }

    public function testRollupRowsMarksProjectRollupNotApplicable(): void
    {
        $response = $this->legacyResponse(ConfigValidateIntegrationPing::ok(), ConfigValidateIntegrationPing::ok());

        $this->assertSame([
            ['config.validate.label_global_rollup' => 'config.validate.rollup_ok'],
            ['config.validate.label_project_rollup' => 'config.validate.rollup_not_applicable'],
        ], $this->view->rollupRows($response));
    }

    public function testRollupRowsFormatsApplicableProjectRollup(): void
    {
        $response = $this->evaluatedResponse(ConfigReadinessEvaluator::ROLLUP_PARTIAL, ConfigReadinessEvaluator::ROLLUP_FAIL);

        $this->assertSame([
            ['config.validate.label_global_rollup' => 'config.validate.rollup_partial'],
            ['config.validate.label_project_rollup' => 'config.validate.rollup_fail'],
        ], $this->view->rollupRows($response));
    }

    public function testRollupRowsKeepsUnknownRollupUnchanged(): void
    {
        $response = $this->evaluatedResponse('custom', ConfigReadinessEvaluator::ROLLUP_OK);

        $this->assertSame(
            ['config.validate.label_global_rollup' => 'custom'],
            $this->view->rollupRows($response)[0],
        );
    }

    public function testIntegrationRowsFollowFixedOrderForEvaluatedIntegrations(): void
    {
        $response = ConfigValidateResponse::fromEvaluation(
            new ConfigReadinessEvaluation(ConfigReadinessEvaluator::ROLLUP_OK, ConfigReadinessEvaluator::ROLLUP_OK, true, []),
            [
                'linear' => ConfigValidateIntegrationPing::fail(MessageRef::key('config.validate.error_linear_ping', ['error' => 'down'])),
                'gitlab' => ConfigValidateIntegrationPing::skipped(),
                'jira' => ConfigValidateIntegrationPing::ok(),
            ],
            'gitlab',
        );

        $this->assertSame([
            ['config.validate.label_jira' => 'config.validate.status_ok'],
            ['config.validate.label_gitlab' => 'config.validate.status_skipped'],
            ['config.validate.label_linear' => 'config.validate.status_fail (config.validate.error_linear_ping {"error":"down"})'],
        ], $this->view->integrationRows($response));
    }

    public function testIntegrationRowsUseConfiguredGitProviderForLegacyResponse(): void
    {
        $response = $this->legacyResponse(
            ConfigValidateIntegrationPing::skipped(),
            ConfigValidateIntegrationPing::skipped(),
            'gitlab',
        );

        $this->assertSame([
            ['config.validate.label_jira' => 'config.validate.status_skipped'],
            ['config.validate.label_gitlab' => 'config.validate.status_skipped'],
            ['config.validate.label_linear' => 'config.validate.status_skipped'],
        ], $this->view->integrationRows($response));
    }

    public function testIntegrationRowsFallBackToGithubWhenGitWasCheckedWithoutProvider(): void
    {
        $response = $this->legacyResponse(
            ConfigValidateIntegrationPing::skipped(),
            ConfigValidateIntegrationPing::fail('timeout'),
        );

        $this->assertSame([
            ['config.validate.label_jira' => 'config.validate.status_skipped'],
            ['config.validate.label_github' => 'config.validate.status_fail (timeout)'],
            ['config.validate.label_linear' => 'config.validate.status_skipped'],
        ], $this->view->integrationRows($response));
    }

    public function testIntegrationRowsOmitGitWhenSkippedWithoutProvider(): void
    {
        $response = $this->legacyResponse(
            ConfigValidateIntegrationPing::skipped(),
            ConfigValidateIntegrationPing::skipped(),
        );

        $this->assertSame([
            ['config.validate.label_jira' => 'config.validate.status_skipped'],
            ['config.validate.label_linear' => 'config.validate.status_skipped'],
        ], $this->view->integrationRows($response));
    }

    public function testIntegrationRowsShowFailLabelWithoutDetailWhenMessageIsEmptyOrMissing(): void
    {
        $response = $this->legacyResponse(
            ConfigValidateIntegrationPing::fail(''),
            new ConfigValidateIntegrationPing(ConfigValidateResponse::STATUS_FAIL),
            'github',
        );

        $this->assertSame([
            ['config.validate.label_jira' => 'config.validate.status_fail'],
            ['config.validate.label_github' => 'config.validate.status_fail'],
        ], $this->view->integrationRows($response));
    }

    public function testIntegrationRowsApplyColorStyles(): void
    {
        $colorHelper = $this->createStub(ColorHelper::class);
        $colorHelper->method('format')->willReturnCallback(static fn (string $style, string $text): string => "<{$style}>{$text}</>");
        $view = new ConfigValidateCliView(new ResponderHelper($this->translationService, $colorHelper));
        $response = $this->legacyResponse(ConfigValidateIntegrationPing::ok(), ConfigValidateIntegrationPing::skipped());

        $this->assertSame(
            ['<definition_key>config.validate.label_jira</>' => '<definition_value>config.validate.status_ok</>'],
            $view->integrationRows($response)[0],
        );
    }

    public function testMessageTextTranslatesMessageRefAndKeepsPlainText(): void
    {
        $this->assertSame(
            'config.validate.notice_linear_non_blocking',
            $this->view->messageText(MessageRef::key('config.validate.notice_linear_non_blocking')),
        );
        $this->assertSame('plain', $this->view->messageText('plain'));
    }

    public function testUnknownIntegrationKeyFallsBackToSkippedWithoutMessage(): void
    {
        $view = new class (new ResponderHelper($this->translationService)) extends ConfigValidateCliView {
            /**
             * @return array{string, string, MessageRef|string|null}
             */
            public function describe(ConfigValidateResponse $response, string $key): array
            {
                return [
                    $this->integrationLabel($key),
                    $this->integrationStatus($response, $key),
                    $this->integrationMessage($response, $key),
                ];
            }
        };
        $response = $this->legacyResponse(ConfigValidateIntegrationPing::ok(), ConfigValidateIntegrationPing::ok());

        $this->assertSame(['custom', ConfigValidateResponse::STATUS_SKIPPED, null], $view->describe($response, 'custom'));
    }

    private function legacyResponse(
        ConfigValidateIntegrationPing $jira,
        ConfigValidateIntegrationPing $git,
        ?string $gitProvider = null,
    ): ConfigValidateResponse {
        return ConfigValidateResponse::create(new ConfigValidatePingResults($jira, $git), $gitProvider);
    }

    private function evaluatedResponse(string $globalRollup, string $projectRollup): ConfigValidateResponse
    {
        return ConfigValidateResponse::fromEvaluation(
            new ConfigReadinessEvaluation($globalRollup, $projectRollup, true, []),
            ['jira' => ConfigValidateIntegrationPing::ok()],
            null,
        );
    }
}
