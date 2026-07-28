import { useCallback, useEffect, useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { subHours } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { Activity, Loader2, Target } from 'lucide-react'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  resolveUsageChartIntervalForRange,
} from '@/lib/usage/chart-interval'
import {
  getUsageLogRetentionDaysFromPlan,
  getUsageLogRetentionHoursFromPlan,
  hasFiniteUsageLogRetention,
  resolveShorterUsageDateRangePreset,
} from '@/lib/usage/usage-log-retention'
import { cn } from '@/lib/utils'
import {
  useFirewallRuleImpact,
  useOrganizationPlan,
  useProject,
} from '@/lib/react-query/hooks'
import type {
  FirewallConditionDraft,
  FirewallResourceType,
} from '@/lib/firewall/conditions'
import {
  countUnestimableFirewallConditions,
  firewallUsageConditionsKey,
} from '@/lib/firewall/usage'
import {
  getFirewallActionLabel,
  type FirewallCreatableAction,
} from '@/lib/firewall/actions'
import { useUsageHistoryLimitAlertState } from '@/hooks/use-usage-history-limit-alert'
import { UsageLogRetentionAlert } from '../../usage/_components/UsageLogRetentionAlert'
import { FirewallImpactChart } from './FirewallImpactChart'
import { useT } from '@/lib/i18n/translate'

const IMPACT_DEBOUNCE_MS = 300

interface RuleImpactPreviewProps {
  conditions: FirewallConditionDraft[]
  action: FirewallCreatableAction
  resourceType: FirewallResourceType
  resourceId?: string
}

export function RuleImpactPreview({
  conditions,
  action,
  resourceType,
  resourceId,
}: RuleImpactPreviewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const [debouncedConditions, setDebouncedConditions] = useState(conditions)
  const [selectedDateRange, setSelectedDateRange] = useState<DateRange>(() => {
    const to = new Date()
    return { from: subHours(to, 24), to }
  })
  const conditionsKey = firewallUsageConditionsKey(conditions)
  const usageLogRetentionHours = useMemo(
    () => getUsageLogRetentionHoursFromPlan(organizationPlan),
    [organizationPlan],
  )
  const usageLogRetentionDays = useMemo(
    () => getUsageLogRetentionDaysFromPlan(organizationPlan),
    [organizationPlan],
  )
  const chartInterval = useMemo(
    () =>
      resolveUsageChartIntervalForRange(
        DEFAULT_USAGE_CHART_INTERVAL,
        selectedDateRange,
        organizationPlan,
      ),
    [organizationPlan, selectedDateRange],
  )

  const { showAlert: showUsageHistoryLimitAlert } =
    useUsageHistoryLimitAlertState({
      projectId,
      dateRange: selectedDateRange,
      retentionHours: usageLogRetentionHours,
      organizationPlan,
    })

  const handleAdjustUsageDateRange = useCallback(() => {
    const fallbackPreset = resolveShorterUsageDateRangePreset(
      usageLogRetentionHours,
    )
    if (fallbackPreset) {
      setSelectedDateRange(fallbackPreset.getRange())
    }
  }, [usageLogRetentionHours])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedConditions(conditions)
    }, IMPACT_DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
    // Re-run when the serialized condition values change, not only array identity.
  }, [conditions, conditionsKey])

  const unestimableConditions = useMemo(
    () => countUnestimableFirewallConditions(debouncedConditions),
    [debouncedConditions],
  )
  // Usage data cannot represent these conditions, so any estimate would
  // overstate matched traffic. Skip the fetch and show "Preview unavailable".
  const previewUnavailable = unestimableConditions > 0

  const { impact, isLoading, isFetching } = useFirewallRuleImpact(
    previewUnavailable ? null : projectId,
    debouncedConditions,
    resourceType,
    resourceId,
    selectedDateRange,
    chartInterval,
    usageLogRetentionHours,
  )

  // Disabled queries keep previous data; never show stale numbers as a preview.
  const series = useMemo(
    () => (previewUnavailable ? [] : (impact?.series ?? [])),
    [previewUnavailable, impact?.series],
  )
  const dateRange = previewUnavailable ? undefined : impact?.dateRange
  const summary = {
    matched: impact?.matched ?? 0,
    rate: impact?.rate ?? 0,
  }

  const filledConditions = conditions.filter((c) => c.value.trim().length > 0)
    .length
  const showSubtleLoading = !previewUnavailable && isFetching && !isLoading

  return (
    <div className="space-y-4">
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="border-b border-border px-4 py-3">
          <div className="flex items-center gap-2">
            <h3 className="text-[14px] font-medium text-foreground">
              {t('Estimated impact')}
            </h3>
            {showSubtleLoading || isLoading ? (
              <Loader2
                className="h-3.5 w-3.5 shrink-0 animate-spin text-muted-foreground"
                aria-label={t('Loading')}
              />
            ) : null}
          </div>
          <p className="mt-1 text-[12px] text-muted-foreground">
            {t(
              'Estimated requests this rule would match during the selected period.',
            )}
          </p>
        </div>

        <div className="space-y-3 border-b border-border p-4">
          <DateRangePicker
            dateRange={selectedDateRange}
            onDateRangeChange={(range) => {
              if (range?.from) {
                setSelectedDateRange({
                  from: range.from,
                  to: range.to ?? range.from,
                })
                return
              }
              const to = new Date()
              setSelectedDateRange({ from: subHours(to, 24), to })
            }}
            className="h-8 w-full min-w-0"
            popoverContentAlign="start"
          />

          {showUsageHistoryLimitAlert &&
          hasFiniteUsageLogRetention(organizationPlan) ? (
            <div className="-mx-4">
              <UsageLogRetentionAlert
                retentionDays={usageLogRetentionDays}
                organizationId={project?.teamId}
                onAdjustRange={handleAdjustUsageDateRange}
              />
            </div>
          ) : null}

          <div
            className={cn(
              'grid grid-cols-2 gap-3 transition-opacity duration-200',
              showSubtleLoading && 'opacity-60',
            )}
          >
            <div className="rounded-lg border border-border bg-muted/20 p-3">
              <div className="mb-1 flex items-center gap-1.5 text-muted-foreground">
                <Target className="h-3.5 w-3.5" />
                <span className="text-[11px]">{t('Matched requests')}</span>
              </div>
              <p className="text-[18px] font-semibold tabular-nums text-foreground">
                {previewUnavailable || (isLoading && !impact)
                  ? '-'
                  : summary.matched.toLocaleString()}
              </p>
            </div>
            <div className="rounded-lg border border-border bg-muted/20 p-3">
              <div className="mb-1 flex items-center gap-1.5 text-muted-foreground">
                <Activity className="h-3.5 w-3.5" />
                <span className="text-[11px]">{t('Share of traffic')}</span>
              </div>
              <p className="text-[18px] font-semibold tabular-nums text-foreground">
                {previewUnavailable || (isLoading && !impact)
                  ? '-'
                  : `${(summary.rate * 100).toFixed(1)}%`}
              </p>
            </div>
          </div>

          {previewUnavailable ? (
            <p className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-[12px] text-amber-700 dark:text-amber-400">
              {t(
                'Preview unavailable: header, query parameter, continent, state, and "does not contain" conditions have no usage data to estimate from. The rule will still enforce them.',
              )}
            </p>
          ) : null}
        </div>

        <div
          className={cn(
            'px-2 pb-2 pt-1 transition-opacity duration-200',
            showSubtleLoading && 'opacity-60',
          )}
        >
          <FirewallImpactChart
            series={series}
            dateRange={dateRange}
            chartInterval={chartInterval}
            emptyLabel={
              previewUnavailable
                ? t('Preview unavailable')
                : isLoading
                  ? t('Loading...')
                  : t('No traffic data for this period')
            }
          />
        </div>
      </div>

      <div className="rounded-xl border border-border bg-card/50 px-4 py-3">
        <p className="text-[12px] text-muted-foreground">
          {t('Action')}:{' '}
          <span className="font-medium text-foreground">
            {t(getFirewallActionLabel(action))}
          </span>
        </p>
        <p className="mt-1 text-[12px] text-muted-foreground">
          {filledConditions === 0
            ? t('Add conditions to narrow which requests this rule matches.')
            : t('Matching estimate updates as you refine conditions.')}
        </p>
      </div>
    </div>
  )
}
