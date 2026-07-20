'use strict';

const RETRYABLE_STATUS_CODES = new Set([408, 409, 425, 429, 500, 502, 503, 504]);

const RETRYABLE_MESSAGE_FRAGMENTS = [
  'Premature close',
  'PREMATURE_CLOSE',
  'fetch failed',
  'ECONNRESET',
  'ETIMEDOUT',
  'socket hang up',
  'other side closed',
  'Invalid response body',
];

const RETRYABLE_ERROR_CODES = new Set([
  'ECONNRESET',
  'ETIMEDOUT',
  'ECONNABORTED',
  'EPIPE',
  'ERR_STREAM_PREMATURE_CLOSE',
  'ERR_SOCKET_CONNECTION_CLOSED',
]);

function collectErrorTokens(error) {
  const tokens = [];
  let current = error;
  for (let depth = 0; current && depth < 5; depth += 1) {
    if (current.message) {
      tokens.push(String(current.message));
    }
    if (current.code) {
      tokens.push(String(current.code));
    }
    current = current.cause;
  }
  return tokens;
}

function isRetryableGoogleError(error) {
  if (!error) {
    return false;
  }

  const status = error.status ?? error.response?.status;
  if (typeof status === 'number') {
    if (RETRYABLE_STATUS_CODES.has(status)) {
      return true;
    }
    if (status >= 500) {
      return true;
    }
  }

  const tokens = collectErrorTokens(error);
  if (tokens.some((token) => RETRYABLE_ERROR_CODES.has(token))) {
    return true;
  }

  return tokens.some((token) => RETRYABLE_MESSAGE_FRAGMENTS.some(
    (fragment) => token.includes(fragment),
  ));
}

async function sleep(ms) {
  await new Promise((resolve) => setTimeout(resolve, ms));
}

async function withGoogleRetry(fn, label, options = {}) {
  const maxAttempts = options.maxAttempts ?? 5;
  const baseDelayMs = options.baseDelayMs ?? 2000;

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    try {
      return await fn();
    } catch (error) {
      const retryable = isRetryableGoogleError(error);
      if (!retryable || attempt === maxAttempts) {
        throw error;
      }
      const delayMs = Math.min(60000, baseDelayMs * (2 ** (attempt - 1)));
      console.log(
        `${label} failed (${error.status ?? error.code ?? error.message}); retrying in ${delayMs}ms (${attempt}/${maxAttempts})`,
      );
      await sleep(delayMs);
    }
  }
}

module.exports = {
  isRetryableGoogleError,
  withGoogleRetry,
};
