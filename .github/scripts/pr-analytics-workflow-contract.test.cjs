'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const WORKFLOW_PATH = path.join(__dirname, '..', 'workflows', 'pr-analytics.yml');

function readWorkflow() {
  return fs.readFileSync(WORKFLOW_PATH, 'utf8');
}

test('pr-analytics workflow uses sync-pr-analytics-sheets.cjs', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /node \.github\/scripts\/sync-pr-analytics-sheets\.cjs/);
});

test('pr-analytics workflow pins googleapis@173.0.0', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /googleapis@173\.0\.0/);
});

test('pr-analytics workflow installs googleapis under isolated NODE_PATH', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /npm_install_cwd="\$\{RUNNER_TEMP:-\/tmp\}\/pr-analytics-googleapis"/);
  assert.match(yaml, /NODE_PATH="\$\{\{\s*steps\.googleapis_deps\.outputs\.node_modules_path\s*\}\}"/);
});

test('pr-analytics workflow passes required Google and repo env vars', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /GOOGLE_SERVICE_ACCOUNT_KEY:\s*\$\{\{\s*secrets\.GOOGLE_SERVICE_ACCOUNT_KEY\s*\}\}/);
  assert.match(yaml, /GOOGLE_SHEET_ID:\s*\$\{\{\s*steps\.set_defaults\.outputs\.google_sheet_id\s*\}\}/);
  assert.match(yaml, /APPEND:\s*\$\{\{\s*steps\.set_defaults\.outputs\.append\s*\}\}/);
  assert.match(yaml, /GITHUB_REPOSITORY_OWNER:\s*\$\{\{\s*github\.repository_owner\s*\}\}/);
  assert.match(yaml, /GITHUB_REPOSITORY_NAME:\s*\$\{\{\s*github\.event\.repository\.name\s*\}\}/);
});

test('pr-analytics workflow schedules exactly one daily cron plus manual dispatch', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /schedule:/);
  assert.match(yaml, /cron:\s*'0 2 \* \* \*'/);
  assert.equal(yaml.match(/- cron:/g).length, 1, 'a single daily tick keeps one scheduled append per day');
  assert.match(yaml, /workflow_dispatch:/);
});

test('pr-analytics workflow never skips a scheduled run on the local Paris hour', () => {
  const yaml = readWorkflow();
  const gateMarkers = [/PARIS_HOUR/, /date \+%H/, /want 04/, /run=false/, /outputs\.run/];

  for (const pattern of gateMarkers) {
    assert.doesNotMatch(yaml, pattern, `workflow must not gate on the clock: ${pattern}`);
  }
});

test('pr-analytics workflow defaults append to true and pins scheduled sync parameters', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /default: 'true'/);
  assert.match(yaml, /github\.event_name.*" = "schedule"/);
  assert.match(yaml, /TZ=Europe\/Paris date -d 'yesterday'/);
  assert.match(yaml, /TARGET_BRANCH="develop"/);
  assert.match(yaml, /APPEND="true"/);
  assert.doesNotMatch(yaml, /APPEND="false"/);
  assert.doesNotMatch(yaml, /default: 'false'/);
});

test('pr-analytics workflow uses Paris calendar-day UTC instants not UTC date labels', () => {
  const yaml = readWorkflow();
  assert.doesNotMatch(yaml, /START_DATE \+ 'T00:00:00Z'/);
  assert.doesNotMatch(yaml, /END_DATE \+ 'T23:59:59Z'/);
  assert.match(yaml, /TZ=Europe\/Paris date -d "\$\{START_DATE\} 00:00:00"/);
  assert.match(yaml, /TZ=Europe\/Paris date -d "\$\{END_DATE\} \+1 day"/);
  assert.match(yaml, /date -u -d "@\$\{START_EPOCH\}"/);
  assert.match(yaml, /date -u -d "@\$\{END_EPOCH\}"/);
  assert.match(yaml, /start_instant=/);
  assert.match(yaml, /end_instant=/);
  assert.match(yaml, /START_INSTANT:\s*\$\{\{\s*steps\.set_defaults\.outputs\.start_instant\s*\}\}/);
  assert.match(yaml, /END_INSTANT:\s*\$\{\{\s*steps\.set_defaults\.outputs\.end_instant\s*\}\}/);
  assert.match(yaml, /new Date\(process\.env\.START_INSTANT\)/);
  assert.match(yaml, /new Date\(process\.env\.END_INSTANT\)/);
});

test('pr-analytics workflow refreshes open and recently closed PRs', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /state:\s*'open'/);
  assert.match(yaml, /state:\s*'closed'/);
  assert.match(yaml, /sort:\s*'updated'/);
  assert.match(yaml, /stopWhenPage:/);
  assert.match(yaml, /pagePRs\.every\(\(pr\) => new Date\(pr\.updated_at\) < startDate\)/);
  assert.doesNotMatch(
    yaml,
    /state:\s*'closed'[\s\S]*stopWhenItem:\s*\(pr\) => new Date\(pr\.updated_at\)/,
  );
});

test('pr-analytics workflow uses the minted App token for github-script', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /github-token:\s*\$\{\{\s*steps\.mint_identity_token\.outputs\.token \|\| github\.token\s*\}\}/);
  assert.doesNotMatch(yaml, /GH_TOKEN:/);
});

test('pr-analytics workflow serializes jobs and bounds runtime', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /timeout-minutes:\s*60/);
  assert.match(yaml, /concurrency:/);
  assert.match(yaml, /group:\s*pr-analytics-\$\{\{\s*github\.repository\s*\}\}/);
  assert.match(yaml, /cancel-in-progress:\s*false/);
});

test('pr-analytics workflow commits reviews only after a PR fetch succeeds', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /const prReviewRows = \[\];/);
  assert.match(yaml, /reviews\.push\(\.\.\.prReviewRows\)/);
  assert.match(yaml, /reviewerLogin/);
});

test('pr-analytics workflow records successfully fetched review PR numbers', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /review_fetched_pr_numbers\.json/);
  assert.match(yaml, /fetchedPrNumbers/);
});

test('pr-analytics workflow paginates pull request reviews', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /github\.rest\.pulls\.listReviews\(/);
  assert.match(yaml, /per_page:\s*100/);
  assert.match(yaml, /hasMoreReviews/);
  assert.match(yaml, /reviewsPage/);
});

test('pr-analytics workflow does not reference coverage, changelog, or gemini paths', () => {
  const yaml = readWorkflow();
  const forbidden = [
    /collect-coverage/i,
    /COVERAGE_/,
    /changelog/i,
    /gemini/i,
    /\.cursor\/prompts/,
    /gemini-phpunit-coverage/i,
  ];

  for (const pattern of forbidden) {
    assert.doesNotMatch(yaml, pattern, `workflow must not match ${pattern}`);
  }
});
