'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {
  SHARED_SHEET_NAMES,
  normalizeSlug,
  buildRepositoryKey,
  formatSpreadsheetPrId,
  formatSheetRange,
  isMissingSheetError,
  readWorkflowInputData,
  mergePRData,
  childBatchIds,
  mergeChildDataByPrId,
  leftoverClearRange,
} = require('./sync-pr-analytics-sheets.cjs');

const prHeaders = ['PR Number', 'Title'];

test('SHARED_SHEET_NAMES lists PRs Reviews and Labels only', () => {
  assert.deepEqual(SHARED_SHEET_NAMES, ['PRs', 'Reviews', 'PRs Labels']);
});

test('normalizeSlug lowercases GitHub slugs and rejects empty values', () => {
  assert.equal(normalizeSlug('Studapart', 'GITHUB_REPOSITORY_OWNER'), 'studapart');
  assert.equal(normalizeSlug('  studa3  ', 'GITHUB_REPOSITORY_NAME'), 'studa3');
  assert.throws(
    () => normalizeSlug('', 'GITHUB_REPOSITORY_OWNER'),
    /GITHUB_REPOSITORY_OWNER is required/,
  );
});

test('buildRepositoryKey joins owner and repo slugs', () => {
  assert.equal(buildRepositoryKey('Studapart', 'studa3'), 'studapart_studa3');
  assert.equal(buildRepositoryKey('studapart', 'other-repo'), 'studapart_other-repo');
});

test('formatSpreadsheetPrId prefixes PR numbers for spreadsheet output', () => {
  assert.equal(formatSpreadsheetPrId('Studapart', 'studa3', 1234), 'studapart_studa3_1234');
  assert.equal(formatSpreadsheetPrId('studapart', 'other-repo', '5678'), 'studapart_other-repo_5678');
});

test('formatSheetRange quotes sheet names for Google Sheets A1 notation', () => {
  assert.equal(formatSheetRange('PRs', 'A1'), "'PRs'!A1");
  assert.equal(formatSheetRange('PRs Labels', 'A:Z'), "'PRs Labels'!A:Z");
  assert.equal(formatSheetRange("owner's_repo", 'A1'), "'owner''s_repo'!A1");
});

test('normalizeSlug rejects whitespace-only values', () => {
  assert.throws(
    () => normalizeSlug('   ', 'GITHUB_REPOSITORY_OWNER'),
    /GITHUB_REPOSITORY_OWNER is required/,
  );
});

test('isMissingSheetError detects missing sheet responses', () => {
  assert.equal(isMissingSheetError({ status: 404 }), true);
  assert.equal(isMissingSheetError({ response: { status: 404 } }), true);
  assert.equal(isMissingSheetError({ message: 'Unable to parse range: PRs!A1' }), true);
  assert.equal(isMissingSheetError({ message: 'Invalid response body while trying to fetch token: Premature close' }), false);
});

test('readWorkflowInputData wraps missing input files with context', () => {
  assert.throws(
    () => readWorkflowInputData({
      readFileSync(path) {
        throw new Error(`ENOENT: no such file or directory, open '${path}'`);
      },
    }),
    /Failed to read or parse input data files \(pr_data\.json, review_data\.json, review_fetched_pr_numbers\.json\): ENOENT/,
  );
});

test('readWorkflowInputData parses workflow input json files', () => {
  const parsed = readWorkflowInputData({
    readFileSync(path) {
      if (path === 'pr_data.json') {
        return '[{"number":1}]';
      }
      if (path === 'review_data.json') {
        return '[{"pr_number":1}]';
      }
      if (path === 'review_fetched_pr_numbers.json') {
        return '[1]';
      }
      throw new Error(`unexpected path: ${path}`);
    },
  });

  assert.deepEqual(parsed.prData, [{ number: 1 }]);
  assert.deepEqual(parsed.reviewData, [{ pr_number: 1 }]);
  assert.deepEqual(parsed.reviewFetchedPrNumbers, [1]);
});

test('mergePRData upserts matching PR ids and appends new rows', () => {
  const existingRows = [prHeaders, ['studapart_studa3_1', 'Old'], ['studapart_studa3_2', 'Keep']];
  const newRows = [['studapart_studa3_1', 'New'], ['other_studa3_1', 'Other repo'], ['studapart_studa3_3', 'Added']];
  const merged = mergePRData(existingRows, newRows, prHeaders, true);

  assert.deepEqual(merged, [
    prHeaders,
    ['studapart_studa3_1', 'New'],
    ['studapart_studa3_2', 'Keep'],
    ['other_studa3_1', 'Other repo'],
    ['studapart_studa3_3', 'Added'],
  ]);
});

test('mergePRData replaces data in override mode', () => {
  const existingRows = [prHeaders, [1, 'Old']];
  const newRows = [[2, 'Fresh']];
  const merged = mergePRData(existingRows, newRows, prHeaders, false);

  assert.deepEqual(merged, [prHeaders, [2, 'Fresh']]);
});

test('mergeChildDataByPrId replaces rows for PRs in the current batch', () => {
  const existingRows = [
    prHeaders,
    ['studapart_studa3_1', 'stale'],
    ['studapart_studa3_1', 'stale-dup'],
    ['studapart_studa3_2', 'keep'],
  ];
  const newRows = [['studapart_studa3_1', 'fresh'], ['studapart_studa3_3', 'added']];
  const merged = mergeChildDataByPrId(existingRows, newRows, prHeaders, true);

  assert.deepEqual(merged, [
    prHeaders,
    ['studapart_studa3_2', 'keep'],
    ['studapart_studa3_1', 'fresh'],
    ['studapart_studa3_3', 'added'],
  ]);
});

test('mergeChildDataByPrId is idempotent for the same batch', () => {
  const existingRows = [prHeaders, ['studapart_studa3_1', 'a'], ['studapart_studa3_2', 'b']];
  const newRows = [['studapart_studa3_1', 'a']];
  const first = mergeChildDataByPrId(existingRows, newRows, prHeaders, true);
  const second = mergeChildDataByPrId(first, newRows, prHeaders, true);

  assert.deepEqual(first, second);
  assert.equal(first.filter((row) => row[0] === 'studapart_studa3_1').length, 1);
});

test('mergeChildDataByPrId replaces data in override mode', () => {
  const existingRows = [prHeaders, [1, 'A']];
  const newRows = [[2, 'B']];
  const merged = mergeChildDataByPrId(existingRows, newRows, prHeaders, false);

  assert.deepEqual(merged, [prHeaders, [2, 'B']]);
});

test('childBatchIds prefers explicit synced PR ids over new row ids', () => {
  assert.deepEqual(
    [...childBatchIds([['from-row']], ['studapart_studa3_1', 'studapart_studa3_2'])],
    ['studapart_studa3_1', 'studapart_studa3_2'],
  );
  assert.deepEqual([...childBatchIds([['from-row']], [])], ['from-row']);
});

test('leftoverClearRange starts after the last written row', () => {
  assert.equal(leftoverClearRange('PRs', 10), "'PRs'!A11:Z");
  assert.equal(leftoverClearRange('PRs Labels', 1), "'PRs Labels'!A2:Z");
  assert.equal(leftoverClearRange('Reviews', 0), "'Reviews'!A2:Z");
});

test('mergeChildDataByPrId drops existing rows for synced PRs with no new child rows', () => {
  const existingRows = [
    prHeaders,
    ['studapart_studa3_1', 'stale-review'],
    ['studapart_studa3_2', 'keep'],
  ];
  const merged = mergeChildDataByPrId(existingRows, [], prHeaders, true, ['studapart_studa3_1']);

  assert.deepEqual(merged, [prHeaders, ['studapart_studa3_2', 'keep']]);
});
