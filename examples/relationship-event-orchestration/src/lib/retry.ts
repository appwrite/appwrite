import { logger } from './logger.js';

export type RetryPolicy = {
  /** Max number of attempts (including the first call). */
  attempts: number;
  /** Base delay in milliseconds for exponential backoff. */
  baseDelayMs: number;
  /** Maximum delay cap. */
  maxDelayMs: number;
  /** Optional predicate to decide whether an error is retryable. */
  isRetryable?: (error: unknown) => boolean;
  /** Optional tag used for structured logging. */
  operation?: string;
};

/**
 * Default retry policy: 5 attempts, full jitter exponential backoff,
 * retries on transient errors only (network, 5xx, 429).
 */
export const DEFAULT_RETRY: RetryPolicy = {
  attempts: 5,
  baseDelayMs: 100,
  maxDelayMs: 5_000,
  isRetryable: defaultIsRetryable,
};

function defaultIsRetryable(error: unknown): boolean {
  if (!error || typeof error !== 'object') {
    return false;
  }
  const err = error as { code?: number; type?: string; name?: string };

  if (err.code === 429) return true;
  if (typeof err.code === 'number' && err.code >= 500 && err.code < 600) {
    return true;
  }
  if (err.name === 'FetchError' || err.name === 'AbortError') return true;
  if (err.type === 'general_server_error') return true;

  return false;
}

/**
 * Execute `fn` with exponential backoff + full jitter.
 *
 * Idempotency MUST be guaranteed by the caller; this helper assumes the
 * operation is safe to repeat (use {@link withIdempotency} for that).
 */
export async function withRetry<T>(
  fn: (attempt: number) => Promise<T>,
  policy: RetryPolicy = DEFAULT_RETRY,
): Promise<T> {
  const isRetryable = policy.isRetryable ?? defaultIsRetryable;

  let lastError: unknown;

  for (let attempt = 1; attempt <= policy.attempts; attempt++) {
    try {
      return await fn(attempt);
    } catch (error) {
      lastError = error;

      const retryable = isRetryable(error);
      const exhausted = attempt >= policy.attempts;

      logger.warn(
        {
          attempt,
          attempts: policy.attempts,
          operation: policy.operation,
          retryable,
          err: serializeError(error),
        },
        retryable && !exhausted
          ? 'transient failure, retrying'
          : 'failure, giving up',
      );

      if (!retryable || exhausted) {
        throw error;
      }

      const expDelay = Math.min(
        policy.maxDelayMs,
        policy.baseDelayMs * 2 ** (attempt - 1),
      );
      const jitter = Math.random() * expDelay;
      await sleep(jitter);
    }
  }

  throw lastError;
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function serializeError(error: unknown): Record<string, unknown> {
  if (!error || typeof error !== 'object') {
    return { error };
  }
  const err = error as Record<string, unknown>;
  return {
    name: err.name,
    message: err.message,
    code: err.code,
    type: err.type,
  };
}
