export const USAGE_HISTORY_LIMIT_EXCEEDED_TYPE = 'limit_history_exceeded'

export function isUsageHistoryLimitExceededError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false

  const candidate = error as {
    code?: number
    status?: number
    type?: string
    message?: string
  }

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

export type UsageChartErrorCopy = {
  title: string
  message: string
  isRetentionLimit: boolean
  retentionDays?: number
}

export function resolveUsageChartErrorCopy(
  error: unknown,
  retentionDays: number,
  fallback: { title: string; message: string },
): UsageChartErrorCopy {
  if (!isUsageHistoryLimitExceededError(error)) {
    return {
      title: fallback.title,
      message: fallback.message,
      isRetentionLimit: false,
    }
  }

  return {
    title: 'Date range exceeds log retention',
    message: fallback.message,
    retentionDays,
    isRetentionLimit: true,
  }
}
