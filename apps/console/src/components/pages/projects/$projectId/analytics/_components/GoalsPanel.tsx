import { useMemo } from 'react'
import { Target } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import {
  ANALYTICS_BREAKDOWN_LIMIT,
  useAnalyticsConversions,
  useAnalyticsGoals,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import {
  BREAKDOWN_COLLAPSED_ROWS,
  BreakdownBody,
  BreakdownHeader,
  BreakdownMessage,
  BreakdownSkeleton,
} from './BreakdownPanel'
import { BreakdownRow } from './BreakdownRow'
import {
  useAnalyticsFilters,
  useAnalyticsGoal,
} from './analytics-filters-context'

/**
 * Converting visitors per goal. Selecting a goal adds its conversion rate to every
 * breakdown; selecting it again clears it.
 */
export function GoalsPanel({
  projectId,
  propertyId,
  range,
  onManage,
}: {
  projectId: string
  propertyId: string
  range: AnalyticsRange
  /** Open goal management (property settings). */
  onManage: () => void
}) {
  const t = useT()
  const { filters } = useAnalyticsFilters()
  const { goalId, selectGoal } = useAnalyticsGoal()
  const {
    goals,
    isLoading: goalsLoading,
    error: goalsError,
  } = useAnalyticsGoals(projectId, propertyId)
  const {
    metrics,
    isLoading,
    error: metricsError,
  } = useAnalyticsConversions(
    projectId,
    propertyId,
    range,
    { dimension: 'goal', limit: ANALYTICS_BREAKDOWN_LIMIT },
    filters,
    goals.length > 0,
  )

  const max = useMemo(
    () => metrics.reduce((m, row) => Math.max(m, row.visitors), 0),
    [metrics],
  )
  const visible = metrics.slice(0, BREAKDOWN_COLLAPSED_ROWS)
  const error = goalsError ?? metricsError

  return (
    <div className="flex flex-col rounded-lg border border-border bg-card">
      <BreakdownHeader
        title="Goals"
        description="Visitors who completed each goal, and the share of all visitors"
        right={
          <Button
            variant="ghost"
            size="sm"
            className="h-7 shrink-0 px-2 text-[12px] text-muted-foreground"
            onClick={onManage}
          >
            {t('Manage goals')}
          </Button>
        }
      />
      <BreakdownBody canShowMore={false} onShowMore={() => {}}>
        {error ? (
          <BreakdownMessage>
            {error instanceof Error
              ? error.message
              : t('Could not load analytics data')}
          </BreakdownMessage>
        ) : goals.length === 0 ? (
          goalsLoading ? (
            <BreakdownSkeleton />
          ) : (
            <BreakdownMessage>
              <span className="flex max-w-sm flex-col items-center gap-2">
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-muted">
                  <Target
                    className="h-4 w-4 text-muted-foreground"
                    aria-hidden
                  />
                </span>
                <span className="text-[13px] font-medium text-foreground">
                  {t('No goals yet')}
                </span>
                <span className="text-[12px] leading-relaxed text-muted-foreground">
                  {t(
                    'Add a goal for a sign-up event or a thank-you page to see conversion rates on every breakdown.',
                  )}
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  className="mt-1 h-8 text-[12px]"
                  onClick={onManage}
                >
                  {t('Add goal')}
                </Button>
              </span>
            </BreakdownMessage>
          )
        ) : isLoading && metrics.length === 0 ? (
          <BreakdownSkeleton />
        ) : (
          <div className="space-y-0.5">
            {visible.map((row) => {
              const id = row.goalId ?? ''
              const active = id === goalId
              return (
                <BreakdownRow
                  key={id}
                  label={row.value || t('Unknown')}
                  value={row.visitors}
                  share={row.conversionRate ?? 0}
                  barPercent={max > 0 ? (row.visitors / max) * 100 : 0}
                  onClick={() => selectGoal(active ? null : id)}
                  active={active}
                  actionTitle={
                    active
                      ? t('Clear goal')
                      : t('Show conversion rates for this goal')
                  }
                />
              )
            })}
          </div>
        )}
      </BreakdownBody>
    </div>
  )
}

/** The selected goal's totals over the window, with a way to clear it. */
export function GoalSummary({
  projectId,
  propertyId,
  range,
}: {
  projectId: string
  propertyId: string
  range: AnalyticsRange
}) {
  const t = useT()
  const { filters } = useAnalyticsFilters()
  const { goalId, selectGoal } = useAnalyticsGoal()
  const { goals } = useAnalyticsGoals(projectId, propertyId)
  const { metrics } = useAnalyticsConversions(
    projectId,
    propertyId,
    range,
    { goalId: goalId ?? undefined },
    filters,
    goalId !== null,
  )

  if (!goalId) return null
  const goal = goals.find((entry) => entry.$id === goalId)
  const totals = metrics[0]

  return (
    <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-lg border border-emerald-500/30 bg-emerald-500/5 px-4 py-3">
      <span className="flex items-center gap-2 text-[13px] font-medium text-foreground">
        <Target
          className="h-4 w-4 text-emerald-600 dark:text-emerald-400"
          aria-hidden
        />
        {goal?.name ?? t('Goal')}
      </span>
      <span className="text-[12px] text-muted-foreground">
        {t('Conversions')}{' '}
        <span className="font-semibold tabular-nums text-foreground">
          {totals?.conversions ?? '–'}
        </span>
      </span>
      <span className="text-[12px] text-muted-foreground">
        {t('Conversion rate')}{' '}
        <span className="font-semibold tabular-nums text-foreground">
          {totals?.conversionRate != null
            ? `${totals.conversionRate.toFixed(1)}%`
            : '–'}
        </span>
      </span>
      <span className="flex-1" />
      <Button
        variant="ghost"
        size="sm"
        className="h-7 px-2 text-[12px]"
        onClick={() => selectGoal(null)}
      >
        {t('Clear goal')}
      </Button>
    </div>
  )
}
