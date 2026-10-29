import { useMemo } from 'react'
import type { Models } from '@appwrite.io/console'
import { useOptionalUsageFilters } from '@/components/pages/projects/$projectId/usage/usage-filters-context'
import {
  DEFAULT_USAGE_LOG_RETENTION_DAYS,
  DEFAULT_USAGE_LOG_RETENTION_HOURS,
  getUsageLogRetentionDaysFromPlan,
  getUsageLogRetentionHoursFromPlan,
} from '@/lib/usage/usage-log-retention'

export function useUsageLogRetentionFromPlan(
  organizationPlan?: Models.BillingPlan | null,
) {
  const context = useOptionalUsageFilters()

  return useMemo(() => {
    if (context) {
      return {
        retentionHours: context.usageLogRetentionHours,
        retentionDays: context.usageLogRetentionDays,
      }
    }

    if (organizationPlan) {
      return {
        retentionHours: getUsageLogRetentionHoursFromPlan(organizationPlan),
        retentionDays: getUsageLogRetentionDaysFromPlan(organizationPlan),
      }
    }

    return {
      retentionHours: DEFAULT_USAGE_LOG_RETENTION_HOURS,
      retentionDays: DEFAULT_USAGE_LOG_RETENTION_DAYS,
    }
  }, [
    context,
    organizationPlan,
    context?.usageLogRetentionHours,
    context?.usageLogRetentionDays,
  ])
}
