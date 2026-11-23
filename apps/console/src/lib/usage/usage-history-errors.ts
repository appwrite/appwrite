export const USAGE_HISTORY_LIMIT_EXCEEDED_TYPE = 'limit_history_exceeded'
export const USAGE_ADDON_NOT_FOUND_TYPE = 'addon_not_found'

function getErrorCandidate(error: unknown): {
  code?: number
  status?: number
  type?: string
  message?: string
} | null {
  if (!error || typeof error !== 'object') return null
  return error as {
    code?: number
    status?: number
    type?: string
    message?: string
  }
}

export function isUsageHistoryLimitExceededError(error: unknown): boolean {
  // Addon errors can also be 402; keep them out of retention-limit handling.
  if (isUsageAddonNotFoundError(error)) return false

  const candidate = getErrorCandidate(error)
  if (!candidate) return false

  const code = candidate.code ?? candidate.status
  if (code === 402) return true
  if (candidate.type === USAGE_HISTORY_LIMIT_EXCEEDED_TYPE) return true

  const message =
    typeof candidate.message === 'string' ? candidate.message.toLowerCase() : ''
  return (
    message.includes('log retention') ||
    message.includes('limit_history_exceeded') ||
    message.includes('history has been exceeded')
  )
}

export function isUsageAddonNotFoundError(error: unknown): boolean {
  const candidate = getErrorCandidate(error)
  if (!candidate) return false

  if (candidate.type === USAGE_ADDON_NOT_FOUND_TYPE) return true

  const message =
    typeof candidate.message === 'string' ? candidate.message.toLowerCase() : ''
  return (
    message.includes('addon_not_found') || message.includes('addon not found')
  )
}

export type UsageChartErrorCopy = {
  title: string
  message: string
  isRetentionLimit: boolean
  isAddonNotFound: boolean
  retentionDays?: number
}

export function shouldSuppressUsageChartRetry(
  copy: Pick<UsageChartErrorCopy, 'isRetentionLimit' | 'isAddonNotFound'>,
): boolean {
  return copy.isRetentionLimit || copy.isAddonNotFound
}

export function resolveUsageChartErrorCopy(
  error: unknown,
  retentionDays: number,
  fallback: { title: string; message: string },
): UsageChartErrorCopy {
  if (isUsageAddonNotFoundError(error)) {
    return {
      title: 'Premium Geo DB required',
      message:
        'Enable the Premium Geo DB addon for this project to view city and country usage breakdowns.',
      isRetentionLimit: false,
      isAddonNotFound: true,
    }
  }

  if (!isUsageHistoryLimitExceededError(error)) {
    return {
      title: fallback.title,
      message: fallback.message,
      isRetentionLimit: false,
      isAddonNotFound: false,
    }
  }

  return {
    title: 'Date range exceeds log retention',
    message: fallback.message,
    retentionDays,
    isRetentionLimit: true,
    isAddonNotFound: false,
  }
}
