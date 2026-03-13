# SCI-68 Implementation Report

## Summary

All 23 methods in `src/` that exceeded CONVENTIONS.md complexity limits (CC > 10 or CRAP > 10) have been refactored so that each method—and any new helpers introduced—meets CC ≤ 10 and CRAP ≤ 10. Behaviour is unchanged; no change to public API or user-visible behaviour.

## Tool used for metrics

- **Command:** `php -dpcov.enabled=1 -dpcov.directory=. -dpcov.exclude="~vendor~" ./vendor/bin/phpunit --coverage-clover phpunit-results.xml`
- **Metrics:** Clover XML `<line type="method">` attributes `complexity` and `crap`. Methods with either > 10 were refactored.

## Requirements checklist

| Requirement | Status |
|-------------|--------|
| A definitive list of methods exceeding CC/CRAP established | ✅ OK – List obtained from Clover report (re-run on current branch). |
| Every method on that list refactored so it (and new helpers) meet CC ≤ 10, CRAP ≤ 10 | ✅ OK – All 23 methods refactored; re-run shows no violations. |
| All existing tests pass; no intentional change to public API or user-visible behaviour | ✅ OK – Full PHPUnit suite passes (1393 tests). |
| Definition of done per method satisfied (refactored, behaviour unchanged, tests kept/added, new methods comply with CONVENTIONS) | ✅ OK – Applied for each refactored method. |
| Optionally: command/tool to measure CC/CRAP documented | ✅ OK – Documented above; CONVENTIONS.md already references PHPUnit/Clover. |

## Files modified

- **HelpService.php** – `formatCommandHelpFromTranslation`, `getCommandHelp`: extracted `getCommandMap`, `buildCommandLineWithAlias`, `buildOptionsLines`, `buildExampleArgs`, `buildUsageSectionLines`, `buildOptionExample`, `extractHelpLinesFromReadme`, `formatHelpText`, `shouldBreakHelpSection`; used lookup tables for argument/option examples.
- **SubmitHandler.php** – `handle`: extracted `runSubmitPreflight`, `buildPrBody`, `fetchJiraDescription`, `convertDescriptionToMarkdown`, `resolveLabels`, `createPullRequest`, `handleExistingPr`; `validateAndProcessLabels`: extracted `parseLabelInput`, `fetchRemoteLabels`, `buildExistingLabelsMap`, `partitionKnownAndUnknownLabels`, `resolveUnknownLabel`, `createLabelOnProvider`.
- **ItemCreateHandler.php** – `handle`: extracted `resolveProjectKeyOrError`, `resolveIssueTypeName`, `buildBaseFields`, `resolveExtrasAndMergeIntoFields`, `createIssueAndReturnResponse`; `promptForExtraRequiredFields` / `getPromptedValueForExtraField`: extracted `extraFieldStandardKind`, `getValueForStandardExtraField`, `valueForDescriptionExtraField`, `promptIssueTypeValue`, `chooseIssueTypeInteractively`, `promptDescriptionValue`; `resolveStandardFieldsAndExtraRequired`: extracted `fillStandardFieldByName`, `applyStandardFieldValue`; `parseOriginalEstimateToSeconds`: extracted `durationUnitToSeconds`, `durationUnitToSecondsMultiplier` (with map). Used constant maps for extra field kinds and duration units.
- **CommitHandler.php** – `handle`: extracted `commitWithMessage`, `resolveLatestLogicalSha`, `commitFixupForSha`, `commitWithJiraPrompt`, `fetchIssueForCommit`, `promptTypeScopeSummary`, `logJiraDetails`.
- **GitRepository.php** – `ensureGitTokenConfigured`: extracted `getGitTokenKeysForProvider`, `resolveGitTokenFromConfig`, `warnGitTokenTypeMismatchIfOppositePresent`, `promptAndSaveGitToken`; `ensureBaseBranchConfigured`: extracted `validateConfiguredBaseBranch`, `resolveDefaultBaseBranchQuiet`, `promptAndSaveBaseBranch`.
- **UpdateHandler.php** – `handle`: extracted `getReleaseOrExitCode`; `isTestEnvironment`: extracted `isTestEnvironmentByConstant`, `isTestEnvironmentByBacktrace`, `isTestEnvironmentByClassOrEnv`.
- **DescriptionFormatter.php** – `parseSections`: extracted `splitDescriptionByDividers`, `processOneSectionToTitleAndContent`; `formatContentForDisplay`: extracted `flushCurrentListItem`, `appendNonCheckboxLine`.
- **ItemStartHandler.php** – `handleTransition`: extracted `tryAssignIssueToCurrentUser`, `resolveTransitionId`, `promptForTransitionId`, `executeTransitionWithLogging`.
- **PrCommentsResponder.php** – `renderSingleComment`: extracted `renderCommentSegment`, `renderTextSegment`, `renderListSegment`.
- **InitHandler.php** – `resolveGitTokenForInit`: extracted `resolveTokenFromUserInput`, `resolveTokenFromExistingKey`, `resolveTokenFromLegacy`.
- **MarkdownToAdfConverter.php** – `convertBlock`: extracted `convertParagraphBlock`, `convertHeadingBlock`, `convertFencedCodeBlock`, `convertBlockquoteBlock`, `convertListBlock`.
- **ItemTransitionHandler.php** – `handle`: extracted `verifyIssueExists`, `fetchTransitionsOrFail`, `selectTransitionIdFromUser`, `executeTransitionAndReturn`.
- **ChangelogParser.php** – `parse`: extracted `addItemToResult`.
- **GitLabProvider.php** – `getPullRequestReviewComments`: extracted `buildReviewCommentsFromDiscussions`, `extractPathAndLineFromPosition`.
- **PageViewConfig.php** – `renderSection`: extracted `partitionSectionItems`.

## Assumptions / deviations

- None. Refactoring only; no new features or observable behaviour changes.
- PHPStan may still report some existing findings (e.g. array value type in iterable); no new PHPStan violations were introduced by the refactor beyond fixing the CommitHandler return type docblock.

## Additional notes

- All new helper methods use `protected` where they contain testable logic, per CONVENTIONS/ADR-008.
- Constant maps (e.g. `EXTRA_FIELD_KIND_KEYS`, `DURATION_UNIT_MULTIPLIERS`) were used to replace long if/else chains and keep CC low.
- Full test suite and coverage run after all changes; no complexity violations remain in `src/`.
