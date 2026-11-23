import { useMemo, type ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import {
  AnalyticsDimension,
  AnalyticsPropertyType,
  type Models,
} from '@appwrite.io/console'
import { BarChart3, Fingerprint } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { USAGE_CHART_FADE_IN_CLASS_NAME } from '@/lib/usage/usage-chart-loading'
import { isKnownBreakdownValue } from '@/lib/analytics/breakdown-values'
import {
  equalFilterEntry,
  type AnalyticsFilter,
} from '@/lib/analytics/analytics-filters'
import { mapToQueryParam } from '@/lib/table-filters'
import {
  getDefaultAnalyticsRange,
  isCustomAnalyticsEvent,
  useAnalyticsBreakdown,
  useAnalyticsEventMetrics,
  useAnalyticsProperties,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import { buildAnalyticsChartPoints } from '../../analytics/_components/chart-series'
import { formatNumber } from '../../analytics/_components/format'
import { MiniStat } from '../../analytics/_components/MiniStat'
import {
  TrendChart,
  type TrendPoint,
} from '../../analytics/_components/TrendChart'
import { BreakdownRow } from '../../analytics/_components/BreakdownRow'

/** Properties read for the tab; projects rarely track more. */
const PROPERTIES_LIMIT = 100
/** Rows fetched per list, re-ranked by this user's events. */
const RANKED_ROWS = 100
/** Rows shown per list. */
const LIST_ROWS = 5

/** The same filter the analytics page writes for "User is X". */
function userFilter(userId: string): AnalyticsFilter {
  return {
    attribute: 'userId',
    query: equalFilterEntry('userId', userId).query,
  }
}

/** `?query=` for the property page filtered to this user. */
function userFilterQuery(userId: string): string {
  const entry = equalFilterEntry('userId', userId)
  return mapToQueryParam(new Map([[entry.key, entry.query]]))
}

/**
 * The user's analytics: one card per property that links signed-in users to
 * events (or that received this user's ID from a server anyway), over the
 * last 30 days, each opening the property filtered to them.
 */
export function UserAnalytics({
  projectId,
  userId,
  displayName,
}: {
  projectId: string
  userId: string
  displayName: string
}) {
  const t = useT()
  const range = useMemo(() => getDefaultAnalyticsRange(), [])
  const { properties, isLoading, error } = useAnalyticsProperties(
    projectId,
    0,
    PROPERTIES_LIMIT,
    '',
  )
  const identifying = properties.filter((property) => property.identifyUsers)
  // Properties that don't identify users can still hold this user's events
  // (sent with an API key); they get a card only once data shows up.
  const others = properties.filter((property) => !property.identifyUsers)

  if (isLoading) {
    return <UserPropertyCardSkeleton />
  }

  if (error) {
    return (
      <EmptyCard
        title={t('Could not load analytics')}
        description={
          error instanceof Error ? error.message : t('Unknown error')
        }
      />
    )
  }

  if (properties.length === 0) {
    return (
      <EmptyCard
        title={t('No analytics yet')}
        description={t(
          "Add an analytics property and turn on User identification to see each signed-in user's pages and events here.",
        )}
        action={
          <Button
            variant="secondary"
            size="sm"
            className="h-9 text-[13px]"
            asChild
          >
            <Link
              to="/projects/$projectId/analytics/add"
              params={{ projectId }}
            >
              {t('Add analytics')}
            </Link>
          </Button>
        }
      />
    )
  }

  return (
    <div className="space-y-4">
      {identifying.map((property) => (
        <UserPropertyCard
          key={property.$id}
          projectId={projectId}
          userId={userId}
          property={property}
          range={range}
        />
      ))}
      {others.map((property) => (
        <UserPropertyCard
          key={property.$id}
          projectId={projectId}
          userId={userId}
          property={property}
          range={range}
          onlyWithData
        />
      ))}
      {identifying.length === 0 ? (
        <EmptyCard
          icon={Fingerprint}
          title={t('Link {name} to their analytics').replace(
            '{name}',
            displayName,
          )}
          description={t(
            "Turn on User identification in a property's settings to record the signed-in user on every event, so their pages, events and sessions show here.",
          )}
          action={
            <Button
              variant="secondary"
              size="sm"
              className="h-9 text-[13px]"
              asChild
            >
              <Link
                to="/projects/$projectId/analytics/$propertyId/settings"
                params={{ projectId, propertyId: properties[0].$id }}
              >
                {t('Open property settings')}
              </Link>
            </Button>
          }
        />
      ) : null}
    </div>
  )
}

function EmptyCard({
  icon: Icon = BarChart3,
  title,
  description,
  action,
}: {
  icon?: typeof BarChart3
  title: string
  description: string
  action?: ReactNode
}) {
  return (
    <div className="rounded-xl border border-border bg-card/50 px-6 py-10 text-center">
      <div className="mx-auto flex h-10 w-10 items-center justify-center rounded-lg border border-border bg-background">
        <Icon className="h-4 w-4 text-muted-foreground" />
      </div>
      <p className="mt-4 text-[14px] font-medium text-foreground">{title}</p>
      <p className="mx-auto mt-1.5 max-w-md text-[13px] text-muted-foreground">
        {description}
      </p>
      {action ? <div className="mt-5 flex justify-center">{action}</div> : null}
    </div>
  )
}

function UserPropertyCardSkeleton() {
  return (
    <div className="h-[420px] animate-pulse rounded-xl border border-border bg-card/50" />
  )
}

/**
 * One property, as this user saw it: events with their daily trend,
 * sessions, pageviews (screens on apps), active days, the custom events they
 * fired and the pages they viewed most.
 */
function UserPropertyCard({
  projectId,
  userId,
  property,
  range,
  onlyWithData = false,
}: {
  projectId: string
  userId: string
  property: Models.AnalyticsProperty
  range: AnalyticsRange
  /** Render nothing unless the user has events in the range. */
  onlyWithData?: boolean
}) {
  const t = useT()
  const filters = useMemo(() => [userFilter(userId)], [userId])
  const mobile = property.type === AnalyticsPropertyType.Mobile
  const agent = property.type === AnalyticsPropertyType.Agent

  // `userId` is an events-only filter: the flat aggregate refuses it, so the
  // totals come from the daily series, which also draws the chart.
  const { data: series, error } = useAnalyticsEventMetrics(
    projectId,
    property.$id,
    null,
    range,
    '1d',
    filters,
  )
  const points = useMemo(
    () => buildAnalyticsChartPoints(series?.points ?? [], range, '1d'),
    [series, range],
  )
  const totals = useMemo(() => {
    const active = points.filter((point) => point.events > 0)
    return {
      events: points.reduce((sum, point) => sum + point.events, 0),
      sessions: points.reduce((sum, point) => sum + point.sessions, 0),
      pageviews: points.reduce((sum, point) => sum + point.pageviews, 0),
      activeDays: active.length,
      lastActive: active.at(-1)?.fullDate ?? null,
    }
  }, [points])
  const trend = useMemo<TrendPoint[]>(
    () =>
      points.map((point) => ({ label: point.fullDate, value: point.events })),
    [points],
  )

  const loaded = series !== undefined
  const hasData = totals.events > 0

  if (onlyWithData && (!loaded || !hasData || error)) return null

  const value = (format: () => string) => (loaded ? format() : null)
  const search = { query: userFilterQuery(userId) }

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex h-16 items-center justify-between gap-3 px-6">
        <div className="min-w-0">
          <h3 className="truncate text-[15px] font-semibold leading-5 text-foreground">
            {property.name}
          </h3>
          <p className="h-4 truncate text-[12px] leading-4 text-muted-foreground">
            {t('Last 30 days')}
          </p>
        </div>
        <p className="shrink-0 text-[12px] text-muted-foreground">
          {loaded && totals.lastActive ? (
            <>
              {t('Last active')}{' '}
              <span className="font-medium text-foreground">
                {totals.lastActive}
              </span>
            </>
          ) : null}
        </p>
      </div>
      <div className="border-t border-border" />

      <div className="grid gap-6 px-6 py-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]">
        <div className="flex min-w-0 flex-col gap-4 lg:h-[176px]">
          <div className="h-[60px]">
            <p className="text-[12px] text-muted-foreground">{t('Events')}</p>
            <div className="mt-1.5 flex h-[34px] items-baseline">
              {loaded ? (
                <span
                  className={cn(
                    'text-[34px] font-semibold leading-none tracking-tight tabular-nums text-foreground',
                    USAGE_CHART_FADE_IN_CLASS_NAME,
                  )}
                >
                  {formatNumber(totals.events)}
                </span>
              ) : (
                <span className="h-8 w-24 animate-pulse self-center rounded-md bg-muted" />
              )}
            </div>
          </div>
          <div className="grid grid-cols-3 gap-2">
            <MiniStat
              label={t('Sessions')}
              value={value(() => formatNumber(totals.sessions))}
            />
            <MiniStat
              label={
                agent ? t('Calls') : mobile ? t('Screen views') : t('Pageviews')
              }
              value={value(() =>
                formatNumber(agent ? totals.events : totals.pageviews),
              )}
            />
            <MiniStat
              label={t('Active days')}
              value={value(() => formatNumber(totals.activeDays))}
            />
          </div>
        </div>

        <div className="flex h-[176px] min-w-0 flex-col rounded-lg border border-border/60 bg-background/40 px-3 pb-2.5 pt-3">
          <div className={cn('relative min-h-0 flex-1', FORCE_LTR_CLASS)}>
            {!loaded ? (
              <div className="absolute inset-x-0 bottom-0 top-6 animate-pulse rounded-md bg-muted/40" />
            ) : hasData ? (
              <div
                className={cn(
                  'absolute inset-0',
                  USAGE_CHART_FADE_IN_CLASS_NAME,
                )}
              >
                <TrendChart data={trend} valueLabel={t('events')} />
              </div>
            ) : (
              <div className="absolute inset-0 flex items-center justify-center px-4 text-center text-[12px] text-muted-foreground">
                {error instanceof Error
                  ? error.message
                  : t('No events from this user in the last 30 days')}
              </div>
            )}
          </div>
          <div className="mt-2 flex h-3 shrink-0 justify-between text-[10px] leading-3 text-muted-foreground">
            <span>{t('30 days ago')}</span>
            <span>{t('Today')}</span>
          </div>
        </div>
      </div>

      {hasData ? (
        <div className="grid gap-6 border-t border-border px-6 py-5 md:grid-cols-2">
          <UserRankedList
            projectId={projectId}
            property={property}
            range={range}
            filters={filters}
            dimension={AnalyticsDimension.EventName}
            title={t('Events')}
            emptyLabel={t('No custom events')}
            include={isCustomAnalyticsEvent}
          />
          {agent ? (
            <UserRankedList
              projectId={projectId}
              property={property}
              range={range}
              filters={filters}
              dimension={AnalyticsDimension.Props}
              propKey="model"
              title={t('Models')}
              emptyLabel={t('No model calls')}
            />
          ) : (
            <UserRankedList
              projectId={projectId}
              property={property}
              range={range}
              filters={filters}
              dimension={AnalyticsDimension.Page}
              title={mobile ? t('Screens') : t('Pages')}
              emptyLabel={
                mobile ? t('No screens viewed') : t('No pages viewed')
              }
              mono
            />
          )}
        </div>
      ) : null}

      <div className="flex h-[68px] items-center justify-end gap-2 border-t border-border bg-muted/30 px-6">
        <Button
          variant="secondary"
          size="sm"
          className="h-9 text-[13px]"
          asChild
        >
          <Link
            to="/projects/$projectId/analytics/$propertyId"
            params={{ projectId, propertyId: property.$id }}
            search={search}
          >
            {t('View in analytics')}
          </Link>
        </Button>
      </div>
    </div>
  )
}

/**
 * A short list ranked by this user's events. The API ranks breakdowns by
 * visitors, which is 1 for every row of a single user, so rows are fetched
 * wider and re-ranked here.
 */
function UserRankedList({
  projectId,
  property,
  range,
  filters,
  dimension,
  propKey,
  title,
  emptyLabel,
  include,
  mono = false,
}: {
  projectId: string
  property: Models.AnalyticsProperty
  range: AnalyticsRange
  filters: readonly AnalyticsFilter[]
  dimension: AnalyticsDimension
  propKey?: string
  title: string
  emptyLabel: string
  include?: (value: string) => boolean
  mono?: boolean
}) {
  const { breakdown, isLoading } = useAnalyticsBreakdown(
    projectId,
    property.$id,
    dimension,
    range,
    RANKED_ROWS,
    true,
    filters,
    propKey,
  )
  const rows = useMemo(
    () =>
      breakdown
        .filter(
          (entry) =>
            isKnownBreakdownValue(entry.value) &&
            (!include || include(entry.value!)),
        )
        .sort((a, b) => b.events - a.events)
        .slice(0, LIST_ROWS),
    [breakdown, include],
  )
  const total = rows.reduce((sum, entry) => sum + entry.events, 0)
  const max = rows[0]?.events ?? 0

  return (
    <div className="min-w-0">
      <p className="mb-2 text-[12px] font-medium text-muted-foreground">
        {title}
      </p>
      <div className="h-[148px] space-y-0.5">
        {isLoading ? (
          Array.from({ length: LIST_ROWS }).map((_, index) => (
            <div
              key={index}
              className="h-7 animate-pulse rounded-md bg-muted/50"
              style={{ opacity: 1 - index * 0.15 }}
            />
          ))
        ) : rows.length === 0 ? (
          <div className="flex h-full items-center justify-center text-[12px] text-muted-foreground">
            {emptyLabel}
          </div>
        ) : (
          rows.map((entry) => (
            <BreakdownRow
              key={entry.value}
              label={entry.value!}
              value={entry.events}
              share={total > 0 ? (entry.events / total) * 100 : 0}
              barPercent={max > 0 ? (entry.events / max) * 100 : 0}
              mono={mono}
            />
          ))
        )}
      </div>
    </div>
  )
}
