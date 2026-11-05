import { useMemo } from 'react'
import type { DateRange } from 'react-day-picker'
import {
  hasFiniteUsageLogRetention,
  isClampedUsageDateRangeWithinRetention,
  isUsageDateRangeNearRetentionLimit,
} from '@/lib/usage/usage-log-retention'
import type { Models } from '@appwrite.io/console'

export function useUsageHistoryLimitAlertState({
  dateRange,
  dateRangePresetId,
  retentionHours,
  organizationPlan,
}: {
  projectId?: string | undefined
  dateRange: DateRange | undefined
  dateRangePresetId?: string | null
  retentionHours: number
  organizationPlan?: Models.BillingPlan | null
}) {
  const withinRetentionAfterClamp = useMemo(
    () =>
      isClampedUsageDateRangeWithinRetention(
        dateRange,
        retentionHours,
        dateRangePresetId,
      ),
    [dateRange, retentionHours, dateRangePresetId],
  )

  const rangeBeyondRetention =
    !!organizationPlan &&
    hasFiniteUsageLogRetention(organizationPlan) &&
    !withinRetentionAfterClamp

  const rangeNearRetentionLimit =
    !!organizationPlan &&
    hasFiniteUsageLogRetention(organizationPlan) &&
    withinRetentionAfterClamp &&
    isUsageDateRangeNearRetentionLimit(
      dateRange,
      retentionHours,
      dateRangePresetId,
    )

  return {
    showAlert: rangeBeyondRetention || rangeNearRetentionLimit,
    triggeredByDateRange: rangeBeyondRetention,
    triggeredByNearRetentionLimit: rangeNearRetentionLimit,
  }
}
