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

test('pr-analytics workflow schedules daily Paris-hour crons', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /schedule:/);
  assert.match(yaml, /cron:\s*'0 2 \* \* \*'/);
  assert.match(yaml, /cron:\s*'0 3 \* \* \*'/);
  assert.match(yaml, /workflow_dispatch:/);
});

test('pr-analytics workflow defaults append to true and gates non-Paris schedule ticks', () => {
  const yaml = readWorkflow();
  assert.match(yaml, /default: 'true'/);
  assert.match(yaml, /github\.event_name.*" = "schedule"/);
  assert.match(yaml, /TZ=Europe\/Paris date \+%H/);
  assert.match(yaml, /TZ=Europe\/Paris date -d 'yesterday'/);
  assert.match(yaml, /TARGET_BRANCH="develop"/);
  assert.match(yaml, /steps\.set_defaults\.outputs\.run == 'true'/);
  assert.match(yaml, /APPEND="true"/);
  assert.doesNotMatch(yaml, /APPEND="false"/);
  assert.doesNotMatch(yaml, /default: 'false'/);
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
