import { useMemo } from 'react'
import { startOfHour, subDays } from 'date-fns'
import type { Models } from '@appwrite.io/console'
import {
  formatUsagePlanOverageRow,
  getUsagePlanOverages,
} from '@/lib/billing/usage-plan-overages'
import { useOrganizationUsage } from '@/lib/react-query/hooks/organizations'
import { useT } from '@/lib/i18n/translate'

const USAGE_WINDOW_DAYS = 30

interface DowngradeUsageWarningProps {
  organizationId: string
  targetPlan: Models.BillingPlan | null | undefined
}

export function DowngradeUsageWarning({
  organizationId,
  targetPlan,
}: DowngradeUsageWarningProps) {
  const t = useT()

  // Hour-aligned so the window does not change the query key on every render.
  const { startDate, endDate } = useMemo(() => {
    const end = startOfHour(new Date())
    return {
      startDate: subDays(end, USAGE_WINDOW_DAYS).toISOString(),
      endDate: end.toISOString(),
    }
  }, [])

  const { usage, isLoading, error } = useOrganizationUsage(
    organizationId,
    startDate,
    endDate,
  )

  const rows = useMemo(
    () => getUsagePlanOverages(usage, targetPlan),
    [usage, targetPlan],
  )

  if (isLoading || error || rows.length === 0) return null

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('You may hit limits on the selected plan')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Your usage in the last 30 days was above these limits. This is a rolling window, not your billing cycle.',
          )}
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        <ul className="space-y-1.5">
          {rows.map((row) => (
            <li
              key={row.id}
              className="flex items-start justify-between gap-3 text-[13px] leading-normal"
            >
              <span className="text-foreground">{t(row.name)}</span>
              <span className="shrink-0 text-red-600 dark:text-red-400">
                {formatUsagePlanOverageRow(row)}
              </span>
            </li>
          ))}
        </ul>

        <p className="text-[13px] text-muted-foreground">
          {t(
            'Going over a plan limit can block the affected projects until the next billing cycle.',
          )}
        </p>
      </div>
    </div>
  )
}
