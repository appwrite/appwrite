import { useId, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { AnalyticsDimension, type Models } from '@appwrite.io/console'
import { Area, AreaChart, ResponsiveContainer, Tooltip, YAxis } from 'recharts'
import {
  Bot,
  Download,
  Eye,
  Globe,
  MousePointerClick,
  Plus,
  Users,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip as UiTooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { USAGE_CHART_FADE_IN_CLASS_NAME } from '@/lib/usage/usage-chart-loading'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canCreateAnalyticsProperty } from '@/lib/console-access-checks'
import { isKnownBreakdownValue } from '@/lib/analytics/breakdown-values'
import { equalFilterEntry } from '@/lib/analytics/analytics-filters'
import { mapToQueryParam } from '@/lib/table-filters'
import {
  getLast24HoursAnalyticsRange,
  getPreviousAnalyticsRange,
  useAnalyticsBreakdown,
  useAnalyticsEventMetrics,
  useAnalyticsProperties,
  useAnalyticsStats,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import {
  analyticsChangePercent,
  buildAnalyticsChartPoints,
} from '../../analytics/_components/chart-series'
import { ChangeBadge } from '../../analytics/_components/ChangeBadge'
import {
  formatDuration,
  formatNumber,
  formatPercent,
} from '../../analytics/_components/format'
import { InstallTrackingDialog } from '../../analytics/_components/InstallTrackingDialog'
import { LiveVisitors } from '../../analytics/_components/LiveVisitors'
import { SourceFavicon } from '../../analytics/_components/BreakdownRow'

/** Same series colour as the analytics overview chart. */
const CHART_COLOR = 'var(--chart-brand)'

// ─── Fixed geometry ─────────────────────────────────────────────────────────
//
// Every state (loading, linked, waiting for traffic, not linked) uses these
// heights, so the card never changes size as data arrives or the state flips.

/** Left column: headline, stat tiles, sources row. */
const LEFT_COLUMN_CLASS = 'flex min-w-0 flex-col gap-4 lg:h-[176px]'
/**
 * Chart frame (chart plus its axis-label row), matching the left column's
 * 176px so both sides line up.
 */
const CHART_FRAME_CLASS =
  'flex h-[176px] min-w-0 flex-col rounded-lg border border-border/60 bg-background/40 px-3 pb-2.5 pt-3'

/** Start-side fade for the not-linked preview (the chart box is forced LTR). */
const PREVIEW_FADE_STYLE = {
  maskImage: 'linear-gradient(to right, transparent, black 60%)',
  WebkitMaskImage: 'linear-gradient(to right, transparent, black 60%)',
} as const
/** Sources row, reserved even when empty. */
const SOURCES_ROW_CLASS = 'flex h-6 min-w-0 items-center gap-1.5 overflow-hidden'

/**
 * Source chips that never get cut mid-word: every chip stays in the row at
 * its natural width, and the ones that don't fit entirely are made
 * invisible. Hiding with `visibility` (not `display`) keeps layout unchanged,
 * so measuring can't loop. Re-measured when the row or any chip resizes
 * (window resize, fonts loading, new data).
 */
function TopSourceChips({
  projectId,
  propertyId,
  sources,
}: {
  projectId: string
  propertyId: string
  sources: Models.AnalyticsMetric[]
}) {
  const t = useT()
  const rowRef = useRef<HTMLDivElement>(null)
  // null until the first measure (runs before paint, so nothing flashes).
  const [fitting, setFitting] = useState<number | null>(null)
  const sourcesKey = sources.map((source) => source.value).join('\n')

  useLayoutEffect(() => {
    const row = rowRef.current
    if (!row) return
    const chips = Array.from(
      row.querySelectorAll<HTMLElement>('[data-source-chip]'),
    )
    const measure = () => {
      const box = row.getBoundingClientRect()
      let count = 0
      for (const chip of chips) {
        const rect = chip.getBoundingClientRect()
        // Half a pixel of slack for subpixel layout; works in RTL too.
        if (rect.left < box.left - 0.5 || rect.right > box.right + 0.5) break
        count++
      }
      setFitting(count)
    }
    measure()
    const observer = new ResizeObserver(measure)
    observer.observe(row)
    for (const chip of chips) observer.observe(chip)
    return () => observer.disconnect()
  }, [sourcesKey])

  return (
    <div ref={rowRef} className="flex min-w-0 flex-1 items-center gap-1.5 overflow-hidden">
      {sources.map((source, index) => {
        const hidden = fitting !== null && index >= fitting
        return (
          // Opens the dashboard filtered to this source.
          <Link
            key={source.value}
            data-source-chip
            to="/projects/$projectId/analytics/$propertyId"
            params={{ projectId, propertyId }}
            search={{ query: sourceFilterQuery(source.value!) }}
            title={t('View analytics for this source')}
            aria-hidden={hidden || undefined}
            tabIndex={hidden ? -1 : undefined}
            className={cn(
              'inline-flex h-6 max-w-[11rem] shrink-0 cursor-pointer items-center gap-1.5 rounded-full border border-border bg-background/60 pe-2 ps-1 text-[11px] text-foreground transition-colors hover:border-foreground/30 hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              hidden && 'invisible',
            )}
          >
            <SourceFavicon value={source.value} />
            <span className="truncate">{source.value}</span>
            <span className="tabular-nums text-muted-foreground">
              {formatNumber(source.visitors)}
            </span>
          </Link>
        )
      })}
    </div>
  )
}

/** `https://www.Example.com:443/x` → `example.com`, for matching. */
export function normalizeSiteHost(value: string | null | undefined): string {
  return (value ?? '')
    .trim()
    .toLowerCase()
    .replace(/^https?:\/\//, '')
    .split('/')[0]
    .split(':')[0]
    .replace(/^www\./, '')
}

/**
 * Sites and analytics properties aren't linked by ID, so a site is matched to
 * the property whose domain is one of the site's domains.
 */
export function findPropertyForSite(
  properties: readonly Models.AnalyticsProperty[],
  siteDomains: readonly string[],
): Models.AnalyticsProperty | undefined {
  const hosts = new Set(siteDomains.map(normalizeSiteHost).filter(Boolean))
  if (hosts.size === 0) return undefined
  return properties.find((property) =>
    hosts.has(normalizeSiteHost(property.domain)),
  )
}

/**
 * `?query=` for the property page with a "Source is X" filter, in the same
 * URL format the page's filter bar writes.
 */
function sourceFilterQuery(source: string): string {
  const entry = equalFilterEntry('referrerSource', source)
  return mapToQueryParam(new Map([[entry.key, entry.query]]))
}

type TrendPoint = { label: string; value: number }

/**
 * Smooth area chart in the style of the analytics and usage charts
 * (monotone curve, brand gradient, hover tooltip), without axes. The latest
 * point carries a dot.
 */
function TrendChart({
  data,
  valueLabel,
  interactive = true,
}: {
  data: TrendPoint[]
  valueLabel: string
  interactive?: boolean
}) {
  const gradientId = useId().replace(/:/g, '')
  const lastIndex = data.length - 1
  return (
    <ResponsiveContainer width="100%" height="100%">
      <AreaChart data={data} margin={{ top: 8, right: 6, bottom: 2, left: 6 }}>
        <defs>
          <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={CHART_COLOR} stopOpacity={0.28} />
            <stop offset="100%" stopColor={CHART_COLOR} stopOpacity={0} />
          </linearGradient>
        </defs>
        <YAxis hide domain={[0, (max: number) => Math.ceil(max * 1.15) || 1]} />
        {interactive ? (
          <Tooltip
            isAnimationActive={false}
            cursor={{ stroke: 'var(--border)' }}
            content={({ active, payload }) => {
              const point = payload?.[0]?.payload as TrendPoint | undefined
              if (!active || !point) return null
              return (
                <div className="rounded-md border border-border bg-popover px-2.5 py-1.5 text-[11px]">
                  <p className="text-muted-foreground">{point.label}</p>
                  <p className="mt-0.5 font-medium tabular-nums text-foreground">
                    {formatNumber(point.value)} {valueLabel}
                  </p>
                </div>
              )
            }}
          />
        ) : null}
        <Area
          type="monotone"
          dataKey="value"
          stroke={CHART_COLOR}
          strokeWidth={2}
          fill={`url(#${gradientId})`}
          isAnimationActive={false}
          activeDot={
            interactive
              ? { r: 4, fill: CHART_COLOR, stroke: 'var(--background)', strokeWidth: 2 }
              : false
          }
          dot={(props: { cx?: number; cy?: number; index?: number }) =>
            props.index === lastIndex && props.cx != null && props.cy != null ? (
              <circle
                key="last"
                cx={props.cx}
                cy={props.cy}
                r={3.5}
                fill={CHART_COLOR}
                stroke="var(--background)"
                strokeWidth={2}
              />
            ) : (
              <g key={props.index} />
            )
          }
        />
      </AreaChart>
    </ResponsiveContainer>
  )
}

function MiniStat({
  icon: Icon,
  label,
  value,
  change,
  invert,
}: {
  icon: typeof Users
  label: string
  value: string | null
  change?: number
  invert?: boolean
}) {
  return (
    <div className="flex h-[52px] min-w-0 items-center gap-2.5 rounded-lg border border-border/60 bg-background/40 px-3">
      <Icon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      <div className="min-w-0">
        <p className="truncate text-[11px] text-muted-foreground">{label}</p>
        <p className="flex h-5 items-baseline gap-1.5">
          {value === null ? (
            <span className="mt-1 h-3 w-10 animate-pulse rounded bg-muted" />
          ) : (
            <>
              <span className="text-[13px] font-semibold tabular-nums text-foreground">
                {value}
              </span>
              <ChangeBadge change={change} invert={invert} />
            </>
          )}
        </p>
      </div>
    </div>
  )
}

/** Faint dot grid behind the chart, like the product empty states. */
const DOT_GRID_STYLE = {
  backgroundImage: 'radial-gradient(var(--border) 1px, transparent 1px)',
  backgroundSize: '12px 12px',
  maskImage: 'linear-gradient(to bottom, black, transparent)',
  WebkitMaskImage: 'linear-gradient(to bottom, black, transparent)',
} as const

/** Example series for the "not linked" preview. */
const PREVIEW_SERIES: TrendPoint[] = [
  3, 5, 4, 7, 6, 9, 8, 12, 10, 14, 13, 17, 15, 19,
].map((value, index) => ({ label: String(index), value }))

/**
 * Analytics on the site's deployments page, for the last 24 hours.
 *
 * - Linked (a property tracks one of the site's domains): headline visitors
 *   with its trend, an hourly chart, live visitors, top sources, and "View
 *   analytics" or "Install" before the first event.
 * - Not linked: a preview of the same layout and "Add analytics", which opens
 *   the wizard prefilled with the site's name and primary domain.
 *
 * Fixed geometry (see above): a skeleton holds the space while loading, so
 * nothing below the card moves.
 */
export function SiteAnalyticsCard({
  projectId,
  siteName,
  siteDomains,
  domainsLoading = false,
}: {
  projectId: string
  siteName: string
  /** Every domain serving the site; the shortest is used to prefill. */
  siteDomains: readonly string[]
  /** While the site's domains load, matching would flash "not linked". */
  domainsLoading?: boolean
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { access } = useOrganizationScopes(project?.teamId)
  const canCreate = canCreateAnalyticsProperty(access, features)
  const [installOpen, setInstallOpen] = useState(false)

  const enabled = features.analytics
  const {
    properties,
    isLoading: propertiesLoading,
    error,
  } = useAnalyticsProperties(enabled ? projectId : null, 0, 100, '')
  const property = useMemo(
    () => findPropertyForSite(properties, siteDomains),
    [properties, siteDomains],
  )

  // 24 whole hours ending with the current one, fixed per mount so the query
  // keys stay stable.
  const range = useMemo(() => getLast24HoursAnalyticsRange(), [])
  const previousRange = useMemo(() => getPreviousAnalyticsRange(range), [range])
  const { stats } = useAnalyticsStats(projectId, property?.$id, range)
  const { stats: previousStats } = useAnalyticsStats(
    projectId,
    property?.$id,
    previousRange,
  )
  const { data: series } = useAnalyticsEventMetrics(
    projectId,
    property?.$id,
    null,
    range,
    '1h',
  )
  const { breakdown: sources, isLoading: sourcesLoading } =
    useAnalyticsBreakdown(
      projectId,
      property?.$id,
      AnalyticsDimension.ReferrerSource,
      range,
      5,
    )
  const trend = useMemo<TrendPoint[]>(
    () =>
      buildAnalyticsChartPoints(series?.points ?? [], range, '1h').map(
        (point) => ({ label: point.fullDate, value: point.visitors }),
      ),
    [series, range],
  )

  // Errors (no access, product unavailable) hide the card entirely; that is
  // decided once, not mid-session, so it doesn't shift anything.
  if (!enabled || error) return null

  const resolving = propertiesLoading || domainsLoading
  const statsLoaded = stats !== undefined
  const hasTraffic =
    !!stats && (stats.events > 0 || stats.visitors > 0 || stats.pageviews > 0)
  const comparable =
    !!previousStats &&
    (previousStats.visitors > 0 || previousStats.pageviews > 0)
  const change = (current?: number, previous?: number) =>
    comparable ? analyticsChangePercent(current, previous) : undefined
  const topSources = sources
    .filter((row) => isKnownBreakdownValue(row.value))
    .slice(0, 3)
  const primaryDomain = [...siteDomains].sort((a, b) => a.length - b.length)[0]

  const value = (format: (s: Models.AnalyticsMetric) => string) =>
    statsLoaded ? format(stats) : null

  return (
    <div className="relative overflow-hidden rounded-xl border border-border bg-card/50">
      {/* Header: fixed h-16 whatever sits on the right. */}
      <div className="relative flex h-16 items-center justify-between gap-3 px-6">
        <div className="flex min-w-0 items-center">
          <div className="min-w-0">
            <h3 className="text-[15px] font-semibold leading-5 text-foreground">
              {t('Analytics')}
            </h3>
            <p className="h-4 truncate text-[12px] leading-4 text-muted-foreground">
              {resolving ? (
                <span className="inline-block h-3 w-40 animate-pulse rounded bg-muted align-middle" />
              ) : property ? (
                `${property.name} · ${t('Last 24 hours')}`
              ) : (
                t('Not tracking this site yet')
              )}
            </p>
          </div>
        </div>
        {!resolving && property ? (
          <LiveVisitors
            projectId={projectId}
            propertyId={property.$id}
            enabled={property.enabled !== false}
          />
        ) : null}
      </div>
      <div className="relative border-t border-border" />

      {/* Body: same grid and heights in every state. */}
      <div className="relative grid gap-6 px-6 py-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]">
        {resolving || property ? (
          <div className={LEFT_COLUMN_CLASS}>
            <div className="h-[60px]">
              <p className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
                <Users className="h-3.5 w-3.5" />
                {t('Visitors')}
              </p>
              <div className="mt-1.5 flex h-[34px] items-baseline gap-2.5">
                {resolving || !statsLoaded ? (
                  <span className="h-8 w-24 animate-pulse self-center rounded-md bg-muted" />
                ) : (
                  <>
                    <span
                      className={cn(
                        'text-[34px] font-semibold leading-none tracking-tight tabular-nums text-foreground',
                        USAGE_CHART_FADE_IN_CLASS_NAME,
                      )}
                    >
                      {formatNumber(stats.visitors)}
                    </span>
                    <ChangeBadge
                      change={change(stats.visitors, previousStats?.visitors)}
                      suffix={
                        <span className="ms-1 font-normal text-muted-foreground">
                          {t('vs previous 24 hours')}
                        </span>
                      }
                    />
                  </>
                )}
              </div>
            </div>
            <div className="grid grid-cols-3 gap-2">
              <MiniStat
                icon={Eye}
                label={t('Pageviews')}
                value={resolving ? null : value((s) => formatNumber(s.pageviews))}
                change={change(stats?.pageviews, previousStats?.pageviews)}
              />
              <MiniStat
                icon={MousePointerClick}
                label={t('Bounce rate')}
                value={resolving ? null : value((s) => formatPercent(s.bounceRate))}
                change={change(stats?.bounceRate, previousStats?.bounceRate)}
                invert
              />
              <MiniStat
                icon={Globe}
                label={t('Visit duration')}
                value={resolving ? null : value((s) => formatDuration(s.visitDuration))}
                change={change(stats?.visitDuration, previousStats?.visitDuration)}
              />
            </div>
            {/* Always present (h-6): sources, a skeleton, or a quiet note. */}
            <div className={SOURCES_ROW_CLASS}>
              <span className="me-1 shrink-0 text-[11px] text-muted-foreground">
                {t('Top sources')}
              </span>
              {resolving || sourcesLoading ? (
                <span className="h-5 w-40 animate-pulse rounded-full bg-muted" />
              ) : topSources.length > 0 ? (
                <TopSourceChips
                  projectId={projectId}
                  propertyId={property!.$id}
                  sources={topSources}
                />
              ) : (
                <span className="text-[11px] text-muted-foreground/70">
                  {t('None yet')}
                </span>
              )}
            </div>
          </div>
        ) : (
          <div className={cn(LEFT_COLUMN_CLASS, 'justify-center gap-3')}>
            <p className="text-[14px] font-medium text-foreground">
              {t('See who visits {site}').replace('{site}', primaryDomain ?? siteName)}
            </p>
            <ul className="space-y-2">
              {[
                { icon: Users, text: 'Visitors, pageviews and bounce rate' },
                { icon: Globe, text: 'Where they come from and where they are' },
                { icon: Bot, text: 'How much of the traffic is bots and AI agents' },
              ].map(({ icon: Icon, text }) => (
                <li
                  key={text}
                  className="flex items-center gap-2 text-[13px] text-muted-foreground"
                >
                  <Icon className="h-3.5 w-3.5 shrink-0" />
                  {t(text)}
                </li>
              ))}
            </ul>
          </div>
        )}

        {/* Chart column: a framed box (chart + label row), same height as
            the left column, in every state. */}
        <div className={CHART_FRAME_CLASS}>
          <div className={cn('relative min-h-0 flex-1', FORCE_LTR_CLASS)}>
            <div aria-hidden className="absolute inset-0" style={DOT_GRID_STYLE} />
            {resolving || (property && series === undefined) ? (
              <div className="absolute inset-x-0 bottom-0 top-6 animate-pulse rounded-md bg-muted/40" />
            ) : property ? (
              hasTraffic ? (
                <div className={cn('absolute inset-0', USAGE_CHART_FADE_IN_CLASS_NAME)}>
                  <TrendChart data={trend} valueLabel={t('visitors')} />
                </div>
              ) : statsLoaded ? (
                <div className="absolute inset-0 flex flex-col items-center justify-center gap-1 text-center">
                  <span className="relative flex h-2 w-2">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-muted-foreground/50 motion-reduce:animate-none" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-muted-foreground/60" />
                  </span>
                  <p className="mt-1 text-[12px] font-medium text-foreground">
                    {t('Waiting for the first visitor')}
                  </p>
                  <p className="text-[11px] text-muted-foreground">
                    {t('Install tracking and load a page on the site.')}
                  </p>
                </div>
              ) : null
            ) : (
              // Not linked: decorative preview, fading in from the start side.
              // A mask (not a card-coloured overlay) so it fades into
              // whatever is behind the frame.
              <div
                className="absolute inset-0 opacity-70"
                style={PREVIEW_FADE_STYLE}
                aria-hidden
              >
                <TrendChart
                  data={PREVIEW_SERIES}
                  valueLabel=""
                  interactive={false}
                />
              </div>
            )}
          </div>
          <div className="mt-2 flex h-3 shrink-0 justify-between text-[10px] leading-3 text-muted-foreground">
            {property || resolving ? (
              <>
                <span>{t('24 hours ago')}</span>
                <span>{t('Now')}</span>
              </>
            ) : null}
          </div>
        </div>
      </div>

      {/* Footer: always one row of h-9 buttons. */}
      <div className="relative flex h-[68px] items-center justify-end gap-2 border-t border-border bg-muted/30 px-6">
        {resolving ? (
          <span className="h-9 w-32 animate-pulse rounded-md bg-muted" />
        ) : property ? (
          <>
            {/* Only once stats say there's no traffic: never flashes in. */}
            {statsLoaded && !hasTraffic ? (
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => setInstallOpen(true)}
              >
                <Download className="me-1.5 h-4 w-4" />
                {t('Install tracking')}
              </Button>
            ) : null}
            <Button size="sm" className="h-9 text-[13px]" asChild>
              <Link
                to="/projects/$projectId/analytics/$propertyId"
                params={{ projectId, propertyId: property.$id }}
              >
                {t('View analytics')}
              </Link>
            </Button>
          </>
        ) : (
          <>
            <p className="me-auto hidden truncate text-[12px] text-muted-foreground sm:block">
              {primaryDomain
                ? t('Creates a property for this domain, then shows a one-line install.')
                : t('Add a domain to this site first.')}
            </p>
            {canCreate && primaryDomain ? (
              <Button size="sm" className="h-9 text-[13px]" asChild>
                <Link
                  to="/projects/$projectId/analytics/add"
                  params={{ projectId }}
                  search={{ name: siteName, domain: primaryDomain }}
                >
                  <Plus className="me-1.5 h-4 w-4" />
                  {t('Add analytics')}
                </Link>
              </Button>
            ) : (
              <UiTooltip>
                <TooltipTrigger asChild>
                  <span tabIndex={0}>
                    <Button size="sm" className="h-9 text-[13px]" disabled>
                      <Plus className="me-1.5 h-4 w-4" />
                      {t('Add analytics')}
                    </Button>
                  </span>
                </TooltipTrigger>
                <TooltipContent className="text-[12px]">
                  {primaryDomain
                    ? t("You don't have permission to create analytics properties.")
                    : t('This site has no domain yet.')}
                </TooltipContent>
              </UiTooltip>
            )}
          </>
        )}
      </div>

      {property ? (
        <InstallTrackingDialog
          open={installOpen}
          onOpenChange={setInstallOpen}
          projectId={projectId}
          property={property}
        />
      ) : null}
    </div>
  )
}
