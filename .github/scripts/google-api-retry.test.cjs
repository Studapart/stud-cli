'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { isRetryableGoogleError, withGoogleRetry } = require('./google-api-retry.cjs');

test('isRetryableGoogleError treats transient HTTP and network failures as retryable', () => {
  assert.equal(isRetryableGoogleError({ status: 504 }), true);
  assert.equal(isRetryableGoogleError({ status: 500 }), true);
  assert.equal(isRetryableGoogleError({ status: 429 }), true);
  assert.equal(isRetryableGoogleError({ message: 'fetch failed' }), true);
  assert.equal(isRetryableGoogleError({ message: 'other side closed' }), true);
  assert.equal(isRetryableGoogleError({ code: 'ECONNRESET', message: 'read ECONNRESET' }), true);
  assert.equal(isRetryableGoogleError({ code: 'ETIMEDOUT' }), true);
  assert.equal(isRetryableGoogleError({ response: { status: 503 } }), true);
  assert.equal(
    isRetryableGoogleError({
      message: 'Invalid response body while trying to fetch https://www.googleapis.com/oauth2/v4/token: Premature close',
    }),
    true,
  );
  assert.equal(isRetryableGoogleError({ code: 'ERR_STREAM_PREMATURE_CLOSE' }), true);
  assert.equal(
    isRetryableGoogleError({
      message: 'Google Sheets read PRs failed',
      cause: { code: 'ERR_STREAM_PREMATURE_CLOSE' },
    }),
    true,
  );
  assert.equal(isRetryableGoogleError({ status: 404 }), false);
  assert.equal(isRetryableGoogleError({ status: 422 }), false);
  assert.equal(isRetryableGoogleError({ message: 'invalid_grant' }), false);
});

test('withGoogleRetry retries retryable failures then succeeds', async () => {
  let attempts = 0;
  const result = await withGoogleRetry(async () => {
    attempts += 1;
    if (attempts < 3) {
      throw new Error('Premature close');
    }
    return 'ok';
  }, 'Google OAuth token', { baseDelayMs: 1, maxAttempts: 5 });

  assert.equal(result, 'ok');
  assert.equal(attempts, 3);
});

test('withGoogleRetry retries ERR_STREAM_PREMATURE_CLOSE failures', async () => {
  let attempts = 0;
  const result = await withGoogleRetry(async () => {
    attempts += 1;
    if (attempts < 2) {
      const error = new Error('stream closed');
      error.code = 'ERR_STREAM_PREMATURE_CLOSE';
      throw error;
    }
    return 'ok';
  }, 'Google Sheets read PRs', { baseDelayMs: 1, maxAttempts: 5 });

  assert.equal(result, 'ok');
  assert.equal(attempts, 2);
});

test('withGoogleRetry does not retry non-retryable failures', async () => {
  let attempts = 0;
  await assert.rejects(
    () => withGoogleRetry(async () => {
      attempts += 1;
      const error = new Error('invalid_grant');
      error.status = 400;
      throw error;
    }, 'Google OAuth token', { baseDelayMs: 1, maxAttempts: 5 }),
  );
  assert.equal(attempts, 1);
});
