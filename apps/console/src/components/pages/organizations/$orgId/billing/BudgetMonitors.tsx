import { useQuery } from '@tanstack/react-query'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  StatusPill,
  ruleState,
} from '@/components/pages/projects/$projectId/usage/monitors/StatusPill'
import {
  organizationBudgetMonitorsQueryOptions,
  useOrganizationById,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const usd = (amount: number) =>
  amount.toLocaleString('en-US', { style: 'currency', currency: 'USD' })

/**
 * The organization's budget alerts as the monitors that send them: their
 * status and the spend they last read. The budget settings above own their
 * thresholds, and they reach the organization's owners like every alert.
 */
export function BudgetMonitors({ orgId }: { orgId?: string }) {
  const t = useT()
  const { organization } = useOrganizationById(orgId)
  const monitors = useQuery(organizationBudgetMonitorsQueryOptions(orgId))

  // Editions without organization monitors answer with an error: nothing to show.
  if (
    !orgId ||
    monitors.isError ||
    !monitors.data ||
    monitors.data.length === 0
  ) {
    return null
  }

  const budget = Number(organization?.billingBudget ?? 0)
  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex flex-wrap items-start justify-between gap-3 px-6 py-4">
        <div>
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Budget monitors')}
          </h3>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t(
              'Sent when additional usage reaches each threshold. They follow the budget cap and alerts above.',
            )}
          </p>
        </div>
      </div>
      <ul className="divide-y divide-border border-t border-border">
        {monitors.data.map((monitor) => {
          const state = ruleState(monitor)
          const percent = monitor.value ?? 0
          return (
            <li
              key={monitor.$id}
              className="grid grid-cols-[84px_minmax(0,1fr)_auto] items-center gap-3 px-6 py-3"
            >
              <StatusPill status={state} />
              <div className="min-w-0">
                <p className="truncate text-[13px] font-medium text-foreground">
                  {monitor.threshold >= 100
                    ? t('Cap reached')
                    : t('{percent}% of {budget}')
                        .replace('{percent}', String(monitor.threshold))
                        .replace('{budget}', usd(budget))}
                </p>
                <p className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
                  <span className="rounded-full bg-muted px-1.5 py-px text-[11px]">
                    {t('Managed by budget')}
                  </span>
                  {monitor.triggeredAt && (
                    <span>
                      {t('Last sent')}{' '}
                      <DateTooltip date={monitor.triggeredAt} />
                    </span>
                  )}
                </p>
              </div>
              <div className="text-end tabular-nums">
                <p
                  className={cn(
                    'text-[13px] font-semibold',
                    state === 'firing' ? 'text-red-500' : 'text-foreground',
                  )}
                >
                  {monitor.value == null ? '–' : usd((budget * percent) / 100)}
                </p>
                <p className="text-[11px] text-muted-foreground">
                  {monitor.value == null
                    ? t('Waiting for the first check')
                    : `${percent}%`}
                </p>
              </div>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
