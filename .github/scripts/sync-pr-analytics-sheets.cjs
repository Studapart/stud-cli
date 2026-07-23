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
    };
  } catch (error) {
    throw new Error(`Failed to read or parse input data files (pr_data.json, review_data.json): ${error.message}`);
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

  const existingPRNumbers = new Set();
  for (let i = 1; i < existingRows.length; i += 1) {
    if (existingRows[i] && existingRows[i][0]) {
      existingPRNumbers.add(String(existingRows[i][0]));
    }
  }

  const newPRsToAdd = newRows.filter((row) => !existingPRNumbers.has(String(row[0])));
  return [...existingRows, ...newPRsToAdd];
}

function appendData(existingRows, newRows, headers, append) {
  if (!append || existingRows.length === 0) {
    return [headers, ...newRows];
  }
  return [...existingRows, ...newRows];
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

  const { prData, reviewData } = readWorkflowInputData();

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
    console.log('Append mode: Adding new data to existing sheets...');
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
    console.log(`  Adding ${newCount} new PR records`);
  } else {
    await clearSheetRange(sheets, spreadsheetId, formatSheetRange('PRs', 'A:Z'), 'Google Sheets clear PRs');
  }

  await updateSheetRange(
    sheets,
    spreadsheetId,
    formatSheetRange('PRs', 'A1'),
    finalPRData,
    'RAW',
    'Google Sheets update PRs',
  );
  console.log(`✅ Wrote ${finalPRData.length - 1} total PR records to PRs sheet`);

  console.log('Updating Reviews sheet...');
  let finalReviewData = [reviewHeaders, ...reviewRows];
  if (append) {
    const existingReviewData = await readExistingData(sheets, spreadsheetId, 'Reviews');
    finalReviewData = appendData(existingReviewData, reviewRows, reviewHeaders, append);
    const newCount = finalReviewData.length - existingReviewData.length;
    console.log(`  Found ${Math.max(0, existingReviewData.length - 1)} existing review records`);
    console.log(`  Adding ${newCount} new review records`);
  } else {
    await clearSheetRange(sheets, spreadsheetId, formatSheetRange('Reviews', 'A:Z'), 'Google Sheets clear Reviews');
  }

  await updateSheetRange(
    sheets,
    spreadsheetId,
    formatSheetRange('Reviews', 'A1'),
    finalReviewData,
    'RAW',
    'Google Sheets update Reviews',
  );
  console.log(`✅ Wrote ${finalReviewData.length - 1} total review records to Reviews sheet`);

  console.log('Updating PRs Labels sheet...');
  let finalLabelsData = [prLabelsHeaders, ...prLabelsRows];
  if (append) {
    const existingLabelsData = await readExistingData(sheets, spreadsheetId, 'PRs Labels');
    finalLabelsData = appendData(existingLabelsData, prLabelsRows, prLabelsHeaders, append);
    const newCount = finalLabelsData.length - existingLabelsData.length;
    console.log(`  Found ${Math.max(0, existingLabelsData.length - 1)} existing label records`);
    console.log(`  Adding ${newCount} new label records`);
  } else {
    await clearSheetRange(sheets, spreadsheetId, formatSheetRange('PRs Labels', 'A:Z'), 'Google Sheets clear PRs Labels');
  }

  await updateSheetRange(
    sheets,
    spreadsheetId,
    formatSheetRange('PRs Labels', 'A1'),
    finalLabelsData,
    'RAW',
    'Google Sheets update PRs Labels',
  );
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
  readWorkflowInputData,
  mergePRData,
  appendData,
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
