'use strict';

const fs = require('fs');
const { withGoogleRetry } = require('./google-api-retry.cjs');

const SHARED_SHEET_NAMES = ['PRs', 'Reviews', 'PRs Labels'];

function normalizeSlug(value, label) {
  const normalized = String(value ?? '').trim().toLowerCase();
  if (!normalized) {
    throw new Error(`${label} is required and must be a non-empty GitHub repository slug.`);
  }
  return normalized;
}

function buildRepositoryKey(owner, repo) {
  return `${normalizeSlug(owner, 'owner')}_${normalizeSlug(repo, 'repo')}`;
}

function formatSpreadsheetPrId(owner, repo, prNumber) {
  return `${buildRepositoryKey(owner, repo)}_${prNumber}`;
}

function formatSheetRange(sheetName, cellRange) {
  const escapedName = String(sheetName).replace(/'/g, "''");
  return `'${escapedName}'!${cellRange}`;
}

function isMissingSheetError(error) {
  const message = String(error?.message || '');
  const status = error?.status ?? error?.response?.status;
  return status === 404 || message.includes('Unable to parse range');
}

function isRangeBeyondGridError(error) {
  return String(error?.message || '').includes('exceeds grid limits');
}

function parseServiceAccountKey() {
  try {
    return JSON.parse(process.env.GOOGLE_SERVICE_ACCOUNT_KEY);
  } catch (error) {
    console.error('Failed to parse GOOGLE_SERVICE_ACCOUNT_KEY. Ensure it is valid JSON.');
    process.exit(1);
  }
}

function readWorkflowInputData(fsImpl = fs) {
  try {
    return {
      prData: JSON.parse(fsImpl.readFileSync('pr_data.json', 'utf8')),
      reviewData: JSON.parse(fsImpl.readFileSync('review_data.json', 'utf8')),
      reviewFetchedPrNumbers: JSON.parse(fsImpl.readFileSync('review_fetched_pr_numbers.json', 'utf8')),
    };
  } catch (error) {
    throw new Error(
      `Failed to read or parse input data files (pr_data.json, review_data.json, review_fetched_pr_numbers.json): ${error.message}`,
    );
  }
}

async function readExistingData(sheets, spreadsheetId, sheetName) {
  try {
    const response = await withGoogleRetry(
      () => sheets.spreadsheets.values.get({
        spreadsheetId,
        range: formatSheetRange(sheetName, 'A:Z'),
      }),
      `Google Sheets read ${sheetName}`,
    );
    return response.data.values || [];
  } catch (error) {
    if (isMissingSheetError(error)) {
      console.log(`⚠️  Sheet "${sheetName}" is missing or empty; treating as no existing data.`);
      return [];
    }
    throw error;
  }
}

async function clearSheetRange(sheets, spreadsheetId, range, label) {
  await withGoogleRetry(
    () => sheets.spreadsheets.values.clear({ spreadsheetId, range }),
    label,
  );
}

async function updateSheetRange(sheets, spreadsheetId, range, values, valueInputOption, label) {
  await withGoogleRetry(
    () => sheets.spreadsheets.values.update({
      spreadsheetId,
      range,
      valueInputOption,
      resource: { values },
    }),
    label,
  );
}

function mergePRData(existingRows, newRows, headers, append) {
  if (!append || existingRows.length === 0) {
    return [headers, ...newRows];
  }

  const byId = new Map();
  for (let i = 1; i < existingRows.length; i += 1) {
    if (existingRows[i] && existingRows[i][0]) {
      byId.set(String(existingRows[i][0]), existingRows[i]);
    }
  }
  for (const row of newRows) {
    byId.set(String(row[0]), row);
  }

  const seen = new Set();
  const merged = [headers];
  for (let i = 1; i < existingRows.length; i += 1) {
    const id = existingRows[i] && existingRows[i][0] ? String(existingRows[i][0]) : '';
    if (!id || seen.has(id)) {
      continue;
    }
    merged.push(byId.get(id));
    seen.add(id);
  }
  for (const row of newRows) {
    const id = String(row[0]);
    if (seen.has(id)) {
      continue;
    }
    merged.push(row);
    seen.add(id);
  }
  return merged;
}

function childBatchIds(newRows, batchPrIds) {
  if (Array.isArray(batchPrIds) && batchPrIds.length > 0) {
    return new Set(batchPrIds.map((id) => String(id)));
  }
  return new Set(newRows.map((row) => String(row[0])));
}

function mergeChildDataByPrId(existingRows, newRows, headers, append, batchPrIds) {
  if (!append || existingRows.length === 0) {
    return [headers, ...newRows];
  }

  const batchIds = childBatchIds(newRows, batchPrIds);
  const kept = [];
  for (let i = 1; i < existingRows.length; i += 1) {
    const row = existingRows[i];
    if (!row || !row[0] || batchIds.has(String(row[0]))) {
      continue;
    }
    kept.push(row);
  }
  return [headers, ...kept, ...newRows];
}

function leftoverClearRange(sheetName, writtenRowCount) {
  const firstUnusedRow = Math.max(1, Number(writtenRowCount) || 0) + 1;
  return formatSheetRange(sheetName, `A${firstUnusedRow}:Z`);
}

async function replaceSheetValues(sheets, spreadsheetId, sheetName, values) {
  await updateSheetRange(
    sheets,
    spreadsheetId,
    formatSheetRange(sheetName, 'A1'),
    values,
    'RAW',
    `Google Sheets update ${sheetName}`,
  );
  const leftoverRange = leftoverClearRange(sheetName, values.length);
  try {
    await clearSheetRange(
      sheets,
      spreadsheetId,
      leftoverRange,
      `Google Sheets clear leftover ${sheetName}`,
    );
  } catch (error) {
    // The written block can fill the grid exactly, leaving no row to trim.
    if (!isRangeBeyondGridError(error)) {
      throw error;
    }
    console.log(`  No leftover rows to clear in "${sheetName}" (${leftoverRange} is outside the grid).`);
  }
}

async function main() {
  const { google } = require('googleapis');
  const serviceAccountKey = parseServiceAccountKey();

  const auth = new google.auth.GoogleAuth({
    credentials: serviceAccountKey,
    scopes: ['https://www.googleapis.com/auth/spreadsheets'],
  });

  const sheets = google.sheets({ version: 'v4', auth });
  const spreadsheetId = process.env.GOOGLE_SHEET_ID;
  const append = process.env.APPEND === 'true';

  const { prData, reviewData, reviewFetchedPrNumbers } = readWorkflowInputData();

  const [defaultOwner, defaultRepo] = String(process.env.GITHUB_REPOSITORY || '').split('/');
  const ownerSlug = normalizeSlug(
    process.env.GITHUB_REPOSITORY_OWNER || defaultOwner,
    'GITHUB_REPOSITORY_OWNER',
  );
  const repoSlug = normalizeSlug(
    process.env.GITHUB_REPOSITORY_NAME || defaultRepo,
    'GITHUB_REPOSITORY_NAME',
  );

  const formatPrId = (prNumber) => formatSpreadsheetPrId(ownerSlug, repoSlug, prNumber);
  const syncedPrIds = prData.map((pr) => formatPrId(pr.number));
  const reviewFetchedPrIds = (Array.isArray(reviewFetchedPrNumbers) ? reviewFetchedPrNumbers : [])
    .map((prNumber) => formatPrId(prNumber));

  const prHeaders = [
    'PR Number', 'Title', 'Creator', 'Created At', 'Merged At',
    'State', 'Base Branch', 'Head Branch', 'Time to Merge (hours)', 'Time to Merge (days)',
  ];

  const prRows = prData.map((pr) => [
    formatPrId(pr.number),
    pr.title,
    pr.creator,
    pr.created_at,
    pr.merged_at,
    pr.state,
    pr.base_branch,
    pr.head_branch,
    pr.time_to_merge_hours !== null ? pr.time_to_merge_hours : '',
    pr.time_to_merge_days !== null ? pr.time_to_merge_days : '',
  ]);

  const reviewHeaders = [
    'PR Number', 'Reviewer', 'Requested At', 'Submitted At',
    'Review State', 'Time to Review (hours)', 'Time to Review (days)',
  ];

  const reviewRows = reviewData.map((review) => [
    formatPrId(review.pr_number),
    review.reviewer,
    review.requested_at,
    review.submitted_at,
    review.review_state,
    review.time_to_review_hours !== '' ? review.time_to_review_hours : '',
    review.time_to_review_days !== '' ? review.time_to_review_days : '',
  ]);

  const prLabelsHeaders = ['PR Number', 'Label'];
  const prLabelsRows = [];
  for (const pr of prData) {
    if (pr.labels && pr.labels.length > 0) {
      for (const label of pr.labels) {
        prLabelsRows.push([formatPrId(pr.number), label]);
      }
    } else {
      prLabelsRows.push([formatPrId(pr.number), '']);
    }
  }

  if (append) {
    console.log('Append mode: Merging into existing sheets, then writing and trimming leftover rows...');
  } else {
    console.log('Override mode: Clearing and replacing all data in sheets...');
  }

  console.log('Updating PRs sheet...');
  let finalPRData = [prHeaders, ...prRows];
  if (append) {
    const existingPRData = await readExistingData(sheets, spreadsheetId, 'PRs');
    finalPRData = mergePRData(existingPRData, prRows, prHeaders, append);
    const newCount = finalPRData.length - existingPRData.length;
    console.log(`  Found ${Math.max(0, existingPRData.length - 1)} existing PR records`);
    console.log(`  Merged PRs sheet to ${finalPRData.length - 1} rows (delta ${newCount})`);
  }
  await replaceSheetValues(sheets, spreadsheetId, 'PRs', finalPRData);
  console.log(`✅ Wrote ${finalPRData.length - 1} total PR records to PRs sheet`);

  console.log('Updating Reviews sheet...');
  let finalReviewData = [reviewHeaders, ...reviewRows];
  if (append) {
    const existingReviewData = await readExistingData(sheets, spreadsheetId, 'Reviews');
    finalReviewData = mergeChildDataByPrId(
      existingReviewData,
      reviewRows,
      reviewHeaders,
      append,
      reviewFetchedPrIds,
    );
    const newCount = finalReviewData.length - existingReviewData.length;
    console.log(`  Found ${Math.max(0, existingReviewData.length - 1)} existing review records`);
    console.log(`  Merged Reviews sheet to ${finalReviewData.length - 1} rows (delta ${newCount})`);
  }
  await replaceSheetValues(sheets, spreadsheetId, 'Reviews', finalReviewData);
  console.log(`✅ Wrote ${finalReviewData.length - 1} total review records to Reviews sheet`);

  console.log('Updating PRs Labels sheet...');
  let finalLabelsData = [prLabelsHeaders, ...prLabelsRows];
  if (append) {
    const existingLabelsData = await readExistingData(sheets, spreadsheetId, 'PRs Labels');
    finalLabelsData = mergeChildDataByPrId(
      existingLabelsData,
      prLabelsRows,
      prLabelsHeaders,
      append,
      syncedPrIds,
    );
    const newCount = finalLabelsData.length - existingLabelsData.length;
    console.log(`  Found ${Math.max(0, existingLabelsData.length - 1)} existing label records`);
    console.log(`  Merged PRs Labels sheet to ${finalLabelsData.length - 1} rows (delta ${newCount})`);
  }
  await replaceSheetValues(sheets, spreadsheetId, 'PRs Labels', finalLabelsData);
  console.log(`✅ Wrote ${finalLabelsData.length - 1} total PR label records to PRs Labels sheet`);

  console.log('✅ Data sync completed successfully');
}

module.exports = {
  SHARED_SHEET_NAMES,
  normalizeSlug,
  buildRepositoryKey,
  formatSpreadsheetPrId,
  formatSheetRange,
  isMissingSheetError,
  isRangeBeyondGridError,
  readWorkflowInputData,
  mergePRData,
  childBatchIds,
  mergeChildDataByPrId,
  leftoverClearRange,
  replaceSheetValues,
};

if (require.main === module) {
  main().catch((error) => {
    const status = error.status ?? error.code ?? error.response?.status;
    console.error('Error syncing to Google Sheets:', error.message);
    if (status !== undefined) {
      console.error(`HTTP/status code: ${status}`);
    }
    if (error.message.includes('Unable to parse range')) {
      console.error(
        `Make sure the spreadsheet has shared sheets named ${SHARED_SHEET_NAMES.map((name) => `"${name}"`).join(', ')}.`,
      );
    }
    process.exit(1);
  });
}
