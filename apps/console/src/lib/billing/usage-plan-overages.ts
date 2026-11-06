import type { Models } from '@appwrite.io/console'
import { getBillingPlanResourceLimit } from '@/lib/billing/project-breakdown-resources'
import {
  formatCompactBytes,
  formatCompactCount,
  formatGbHoursValue,
  mbSecondsToGbHours,
} from '@/lib/usage/format-metric'

export type UsagePlanOverageFormat = 'bytes' | 'number' | 'gbhours' | 'sms'

export type UsagePlanOverageRow = {
  id: string
  name: string
  usage: number
  limit: number
  format: UsagePlanOverageFormat
}

type UsagePlanOverageDefinition = {
  id: string
  name: string
  planKey: string
  format: UsagePlanOverageFormat
  getUsage: (usage: Models.UsageOrganization) => number
}

/** Newer totals can be absent on older API responses. */
function toTotal(value: number | null | undefined): number {
  if (typeof value !== 'number' || !Number.isFinite(value)) return 0
  return Math.max(0, value)
}

/** `UsageOrganization` has no `bandwidthTotal`; the org total is the metric series summed. */
function sumMetricValues(metrics: Models.Metric[] | null | undefined): number {
  if (!Array.isArray(metrics)) return 0
  return metrics.reduce((total, metric) => total + toTotal(metric?.value), 0)
}

const USAGE_PLAN_OVERAGE_DEFINITIONS: UsagePlanOverageDefinition[] = [
  {
    id: 'bandwidth',
    name: 'Bandwidth',
    planKey: 'bandwidth',
    format: 'bytes',
    getUsage: (usage) => sumMetricValues(usage.bandwidth),
  },
  {
    id: 'storage',
    name: 'Storage',
    planKey: 'storage',
    format: 'bytes',
    getUsage: (usage) => toTotal(usage.storageTotal),
  },
  {
    id: 'mau',
    name: 'MAU',
    planKey: 'users',
    format: 'number',
    getUsage: (usage) => toTotal(usage.usersTotal),
  },
  {
    id: 'executions',
    name: 'Executions',
    planKey: 'executions',
    format: 'number',
    getUsage: (usage) => toTotal(usage.executionsTotal),
  },
  {
    id: 'GBHours',
    name: 'GB-hours',
    planKey: 'gbHours',
    format: 'gbhours',
    getUsage: (usage) =>
      mbSecondsToGbHours(
        toTotal(usage.executionsMBSecondsTotal) +
          toTotal(usage.buildsMBSecondsTotal),
      ),
  },
  {
    id: 'databasesReads',
    name: 'Database reads',
    planKey: 'databaseReads',
    format: 'number',
    getUsage: (usage) => toTotal(usage.databasesReadsTotal),
  },
  {
    id: 'databasesWrites',
    name: 'Database writes',
    planKey: 'databaseWrites',
    format: 'number',
    getUsage: (usage) => toTotal(usage.databasesWritesTotal),
  },
  {
    id: 'imageTransformations',
    name: 'Image transformations',
    planKey: 'imageTransformations',
    format: 'number',
    getUsage: (usage) => toTotal(usage.imageTransformationsTotal),
  },
  {
    id: 'screenshotsGenerated',
    name: 'Screenshots generated',
    planKey: 'screenshotsGenerated',
    format: 'number',
    getUsage: (usage) => toTotal(usage.screenshotsGeneratedTotal),
  },
  {
    id: 'authPhone',
    name: 'Phone OTP',
    planKey: 'authPhone',
    format: 'sms',
    getUsage: (usage) => toTotal(usage.authPhoneTotal),
  },
  {
    id: 'realtime',
    name: 'Realtime connections',
    planKey: 'realtime',
    format: 'number',
    getUsage: (usage) => toTotal(usage.realtimeConnectionsTotal),
  },
  {
    id: 'realtimeMessages',
    name: 'Realtime messages',
    planKey: 'realtimeMessages',
    format: 'number',
    getUsage: (usage) => toTotal(usage.realtimeMessagesTotal),
  },
]

/**
 * Usage rows that would already be over the given plan's limits.
 * Resources the plan leaves unlimited, unset or unavailable (-1) are skipped.
 */
export function getUsagePlanOverages(
  usage: Models.UsageOrganization | null | undefined,
  plan: Models.BillingPlan | null | undefined,
): UsagePlanOverageRow[] {
  if (!usage || !plan) return []

  const rows: UsagePlanOverageRow[] = []

  for (const definition of USAGE_PLAN_OVERAGE_DEFINITIONS) {
    const limit = getBillingPlanResourceLimit(plan, definition.planKey)
    if (limit === null) continue

    const value = definition.getUsage(usage)
    if (value <= limit) continue

    rows.push({
      id: definition.id,
      name: definition.name,
      usage: value,
      limit,
      format: definition.format,
    })
  }

  return rows
}

export function formatUsagePlanOverageValue(
  value: number,
  format: UsagePlanOverageFormat,
): string {
  switch (format) {
    case 'bytes':
      return formatCompactBytes(value)
    case 'gbhours':
      return formatGbHoursValue(value)
    default:
      return formatCompactCount(value)
  }
}

/** `used / limit` for a single overage row. */
export function formatUsagePlanOverageRow(row: UsagePlanOverageRow): string {
  return `${formatUsagePlanOverageValue(row.usage, row.format)} / ${formatUsagePlanOverageValue(row.limit, row.format)}`
}
