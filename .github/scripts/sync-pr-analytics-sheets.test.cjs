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
  appendData,
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
    /Failed to read or parse input data files \(pr_data\.json, review_data\.json\): ENOENT/,
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
      throw new Error(`unexpected path: ${path}`);
    },
  });

  assert.deepEqual(parsed.prData, [{ number: 1 }]);
  assert.deepEqual(parsed.reviewData, [{ pr_number: 1 }]);
});

test('mergePRData deduplicates prefixed PR numbers in append mode', () => {
  const existingRows = [prHeaders, ['studapart_studa3_1', 'Old'], ['studapart_studa3_2', 'Keep']];
  const newRows = [['studapart_studa3_1', 'New'], ['other_studa3_1', 'Other repo'], ['studapart_studa3_3', 'Added']];
  const merged = mergePRData(existingRows, newRows, prHeaders, true);

  assert.deepEqual(merged, [
    prHeaders,
    ['studapart_studa3_1', 'Old'],
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

test('appendData concatenates rows in append mode', () => {
  const existingRows = [prHeaders, [1, 'A']];
  const newRows = [[2, 'B']];
  const merged = appendData(existingRows, newRows, prHeaders, true);

  assert.deepEqual(merged, [prHeaders, [1, 'A'], [2, 'B']]);
});

test('appendData replaces data in override mode', () => {
  const existingRows = [prHeaders, [1, 'A']];
  const newRows = [[2, 'B']];
  const merged = appendData(existingRows, newRows, prHeaders, false);

  assert.deepEqual(merged, [prHeaders, [2, 'B']]);
});
