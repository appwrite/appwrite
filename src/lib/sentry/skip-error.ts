import { isStaleChunkLoadError } from '@/lib/stale-chunk-error'
import { isIndexedDBMutationError } from '@/lib/upload-queue/indexeddb'

function getErrorCode(error: unknown): number | undefined {
  if (!error || typeof error !== 'object') return undefined
  const withCode = error as { code?: number; status?: number }
  return withCode.code ?? withCode.status
}

/** Errors we intentionally do not send to Sentry (auth redirects, deploy churn, etc.). */
export function shouldSkipSentryError(error: unknown): boolean {
  if (getErrorCode(error) === 401) return true
  if (isStaleChunkLoadError(error)) return true
  if (isIndexedDBMutationError(error)) return true
  return false
}
