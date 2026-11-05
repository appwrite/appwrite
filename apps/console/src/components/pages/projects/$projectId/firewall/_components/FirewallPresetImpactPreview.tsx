import { useEffect, useMemo, useState } from 'react'
import { subHours } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import { Activity, Loader2, Target } from 'lucide-react'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  resolveUsageChartIntervalForRange,
} from '@/lib/usage/chart-interval'
import { getUsageLogRetentionDaysFromPlan, getUsageLogRetentionHoursFromPlan } from '@/lib/usage/usage-log-retention'
import { cn } from '@/lib/utils'
import {
  useFirewallRuleImpact,
  useFirewallRuleImpactUnion,
  useOrganizationPlan,
  useProject,
} from '@/lib/react-query/hooks'
import type {
  FirewallConditionDraft,
  FirewallResourceType,
} from '@/lib/firewall/conditions'
import {
  countUnestimableFirewallConditions,
  exceedsFirewallUsageConditionLimit,
  firewallUsageConditionsKey,
} from '@/lib/firewall/usage'
import type { FirewallCreatableAction } from '@/lib/firewall/actions'
import { FirewallImpactChart } from './FirewallImpactChart'
import {
  FIREWALL_IMPACT_FETCH_ERROR,
  FirewallUsageChartError,
} from './FirewallUsageChartError'
import { useT } from '@/lib/i18n/translate'

const IMPACT_DEBOUNCE_MS = 300
const PRESET_CHART_HEIGHT = 160

export type FirewallPresetCountryImpactMode = 'block' | 'allow'

type FirewallPresetImpactPreviewProps = {
  projectId: string
  resourceType: FirewallResourceType
  resourceId?: string
  conditions: FirewallConditionDraft[]
  /** When the preset creates multiple rules, pass one condition set per rule. */
  conditionSets?: FirewallConditionDraft[][]
  action: FirewallCreatableAction
  approximatePreview?: boolean
  /** Clarifies allow-list vs block-list deny semantics in copy and metrics. */
  countryImpactMode?: FirewallPresetCountryImpactMode
}

function isConditionSetPreviewAvailable(conditions: FirewallConditionDraft[]) {
  const hasCompleteConditions = conditions.some(
    (c) => c.value.trim().length > 0,
  )
  if (!hasCompleteConditions) return false
  if (countUnestimableFirewallConditions(conditions) > 0) return false
  if (exceedsFirewallUsageConditionLimit(conditions)) return false
  return true
}

export function FirewallPresetImpactPreview({
  projectId,
  resourceType,
  resourceId,
  conditions,
  conditionSets,
  action,
  approximatePreview = false,
  countryImpactMode,
}: FirewallPresetImpactPreviewProps) {
  const t = useT()
  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const [debouncedConditions, setDebouncedConditions] = useState(conditions)
  const [debouncedConditionSets, setDebouncedConditionSets] = useState(
    conditionSets ?? [],
  )
  const selectedDateRange = useMemo<DateRange>(() => {
    const to = new Date()
    return { from: subHours(to, 24), to }
  }, [])
  const conditionsKey = firewallUsageConditionsKey(conditions)
  const conditionSetsKey = useMemo(
    () =>
      debouncedConditionSets
        .map((set) => firewallUsageConditionsKey(set))
        .join('|'),
    [debouncedConditionSets],
  )
  const usageLogRetentionHours = useMemo(
    () => getUsageLogRetentionHoursFromPlan(organizationPlan),
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

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedConditions(conditions)
      setDebouncedConditionSets(conditionSets ?? [])
    }, IMPACT_DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
  }, [conditions, conditionsKey, conditionSets, conditionSetsKey])

  const useUnion =
    debouncedConditionSets.length > 1 &&
    debouncedConditionSets.every(isConditionSetPreviewAvailable)

  const singleSet =
    debouncedConditionSets.length === 1
      ? debouncedConditionSets[0]!
      : debouncedConditions

  const singlePreviewUnavailable = !isConditionSetPreviewAvailable(singleSet)

  const unionPreviewUnavailable =
    debouncedConditionSets.length === 0 ||
    !debouncedConditionSets.every(isConditionSetPreviewAvailable)

  const { impact: singleImpact, isLoading: singleLoading, isFetching: singleFetching, isError: singleError, error: singleErrorValue, refetch: refetchSingle } =
    useFirewallRuleImpact(
      !useUnion && !singlePreviewUnavailable ? projectId : null,
      singleSet,
      resourceType,
      resourceId,
      selectedDateRange,
      chartInterval,
      usageLogRetentionHours,
      action,
    )

  const { impact: unionImpact, isLoading: unionLoading, isFetching: unionFetching, isError: unionError, error: unionErrorValue, refetch: refetchUnion } =
    useFirewallRuleImpactUnion(
      projectId,
      debouncedConditionSets,
      resourceType,
      resourceId,
      selectedDateRange,
      chartInterval,
      usageLogRetentionHours,
      action,
      useUnion && !unionPreviewUnavailable,
    )

  const impact = useUnion ? unionImpact : singleImpact
  const isLoading = useUnion ? unionLoading : singleLoading
  const isFetching = useUnion ? unionFetching : singleFetching
  const isError = useUnion ? unionError : singleError
  const error = useUnion ? unionErrorValue : singleErrorValue
  const refetch = useUnion ? refetchUnion : refetchSingle
  const previewUnavailable = useUnion
    ? unionPreviewUnavailable
    : singlePreviewUnavailable

  const hasCompleteConditions = useUnion
    ? debouncedConditionSets.length > 0
    : debouncedConditions.some((c) => c.value.trim().length > 0)

  const series = useMemo(
    () => (previewUnavailable ? [] : (impact?.series ?? [])),
    [previewUnavailable, impact?.series],
  )
  const summary = {
    matched: impact?.matched ?? 0,
    rate: impact?.rate ?? 0,
  }
  const showSubtleLoading =
    !previewUnavailable && !isError && isFetching && !isLoading
  const hideImpactValues = previewUnavailable || !impact
  const [keepImpactError, setKeepImpactError] = useState(false)
  if (impact || previewUnavailable) {
    if (keepImpactError) setKeepImpactError(false)
  } else if (isError && !keepImpactError) {
    setKeepImpactError(true)
  }
  const showImpactError =
    !previewUnavailable && !impact && (isError || keepImpactError)

  const emptyLabel = !hasCompleteConditions
    ? t('Select options above to preview matched traffic.')
    : previewUnavailable
      ? t('Preview unavailable')
      : isLoading
        ? t('Loading...')
        : t('No traffic data for this period')

  const unestimableConditions = countUnestimableFirewallConditions(singleSet)
  const tooManyConditions = exceedsFirewallUsageConditionLimit(singleSet)

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-muted/20">
      <div className="border-b border-border px-3 py-2.5">
        <div className="flex items-center gap-2">
          <h4 className="text-[13px] font-medium text-foreground">
            {t('Estimated impact')}
          </h4>
          {showSubtleLoading || isLoading ? (
            <Loader2
              className="h-3.5 w-3.5 shrink-0 animate-spin text-muted-foreground"
              aria-label={t('Loading')}
            />
          ) : null}
        </div>
        <p className="mt-0.5 text-[11px] text-muted-foreground">
          {useUnion
            ? t(
                'Last 24 hours. Combined estimate for every rule this preset will create.',
              )
            : countryImpactMode === 'allow'
              ? t(
                  'Last 24 hours of API traffic that would be denied because it is not from an allowed country.',
                )
              : countryImpactMode === 'block'
                ? t(
                    'Last 24 hours of API traffic from selected countries that would be denied.',
                  )
                : t(
                    'Last 24 hours of project API traffic that matches these conditions.',
                  )}
        </p>
        {approximatePreview ? (
          <p className="mt-1 text-[11px] text-muted-foreground">
            {t(
              'Preview is approximate when multiple rules will be created. Total impact may be higher.',
            )}
          </p>
        ) : null}
      </div>

      <div
        className={cn(
          'grid grid-cols-2 gap-2 border-b border-border p-3 transition-opacity duration-200',
          showSubtleLoading && 'opacity-60',
        )}
      >
        <div className="rounded-md border border-border bg-background/80 p-2.5">
          <div className="mb-0.5 flex items-center gap-1.5 text-muted-foreground">
            <Target className="h-3 w-3" />
            <span className="text-[10px]">
              {countryImpactMode
                ? t('Requests that would be denied')
                : t('Matched requests')}
            </span>
          </div>
          <p className="text-[15px] font-semibold tabular-nums text-foreground">
            {hideImpactValues
              ? '-'
              : summary.matched.toLocaleString()}
          </p>
        </div>
        <div className="rounded-md border border-border bg-background/80 p-2.5">
          <div className="mb-0.5 flex items-center gap-1.5 text-muted-foreground">
            <Activity className="h-3 w-3" />
            <span className="text-[10px]">{t('Share of traffic')}</span>
          </div>
          <p className="text-[15px] font-semibold tabular-nums text-foreground">
            {hideImpactValues
              ? '-'
              : `${(summary.rate * 100).toFixed(1)}%`}
          </p>
        </div>
      </div>

      {previewUnavailable && hasCompleteConditions ? (
        <p className="border-b border-border px-3 py-2 text-[11px] text-muted-foreground">
          {unestimableConditions > 0
            ? t(
                'This preset uses conditions that cannot be estimated from usage logs.',
              )
            : tooManyConditions
              ? t(
                  'Too many conditions to estimate from usage logs for this preview.',
                )
              : null}
        </p>
      ) : null}

      <div
        className={cn(
          'px-1 pb-1 pt-0.5 transition-opacity duration-200',
          showSubtleLoading && 'opacity-60',
        )}
      >
        {showImpactError ? (
          <div className="p-3" style={{ height: PRESET_CHART_HEIGHT + 24 }}>
            <FirewallUsageChartError
              error={error}
              retentionDays={getUsageLogRetentionDaysFromPlan(organizationPlan)}
              fallback={FIREWALL_IMPACT_FETCH_ERROR}
              onRetry={() => void refetch()}
            />
          </div>
        ) : (
        <FirewallImpactChart
          series={series}
          dateRange={previewUnavailable ? undefined : impact?.dateRange}
          chartInterval={chartInterval}
          height={PRESET_CHART_HEIGHT}
          emptyLabel={emptyLabel}
          compactMatchedScale
        />
        )}
      </div>
    </div>
  )
}
