'use client'

import { useMemo } from 'react'
import { OverviewChartPanelError } from '../../overview/OverviewChartPanelError'
import { UsageChartErrorMessage } from '../../shared/UsageChartErrorMessage'
import {
  resolveUsageChartErrorCopy,
  shouldSuppressUsageChartRetry,
} from '@/lib/usage/usage-history-errors'
import { DEFAULT_USAGE_LOG_RETENTION_DAYS } from '@/lib/usage/usage-log-retention'

export const FIREWALL_TRAFFIC_FETCH_ERROR = {
  title: "Couldn't load firewall traffic",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

export const FIREWALL_IMPACT_FETCH_ERROR = {
  title: "Couldn't load impact estimate",
  message:
    "We couldn't fetch usage data from the server. Check your connection and try again.",
} as const

type FirewallUsageChartErrorProps = {
  error?: unknown
  retentionDays?: number
  fallback?: { title: string; message: string }
  onRetry?: () => void
}

export function FirewallUsageChartError({
  error,
  retentionDays,
  fallback = FIREWALL_TRAFFIC_FETCH_ERROR,
  onRetry,
}: FirewallUsageChartErrorProps) {
  const copy = useMemo(
    () =>
      resolveUsageChartErrorCopy(
        error,
        retentionDays ?? DEFAULT_USAGE_LOG_RETENTION_DAYS,
        fallback,
      ),
    [error, fallback, retentionDays],
  )

  return (
    <OverviewChartPanelError
      title={copy.title}
      message={<UsageChartErrorMessage copy={copy} />}
      onRetry={
        onRetry && !shouldSuppressUsageChartRetry(copy) ? onRetry : undefined
      }
    />
  )
}
