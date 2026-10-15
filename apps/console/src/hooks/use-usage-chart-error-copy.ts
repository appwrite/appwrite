import { useMemo } from 'react'
import { useUsageLogRetentionFromPlan } from '@/hooks/use-usage-log-retention-hours'
import { resolveUsageChartErrorCopy } from '@/lib/usage/usage-history-errors'
import type { Models } from '@appwrite.io/console'

export function useUsageChartErrorCopy(
  error: unknown,
  fallback: { title: string; message: string },
  organizationPlan?: Models.BillingPlan | null,
) {
  const { retentionDays } = useUsageLogRetentionFromPlan(organizationPlan)

  return useMemo(
    () => resolveUsageChartErrorCopy(error, retentionDays, fallback),
    [error, retentionDays, fallback.title, fallback.message],
  )
}
