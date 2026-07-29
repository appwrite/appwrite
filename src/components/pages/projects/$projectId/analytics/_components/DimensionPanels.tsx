import { useMemo } from 'react'
import { AnalyticsDimension, type Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'
import type { AnalyticsRange } from '@/lib/react-query/hooks'
import { BreakdownPanel, type BreakdownTab } from './BreakdownPanel'
import { BreakdownRow, CountryFlag, RowDot, RowRank } from './BreakdownRow'
import { formatNumber } from './format'

/** Chart palette, reused so categorical panels stay consistent. */
const SERIES_COLORS = [
  'var(--chart-1)',
  'var(--chart-2)',
  'var(--chart-3)',
  'var(--chart-4)',
  'var(--chart-5)',
]

const colorAt = (index: number) => SERIES_COLORS[index % SERIES_COLORS.length]

type PanelProps = {
  projectId: string
  propertyId: string
  range: AnalyticsRange
}

const SOURCE_TABS: BreakdownTab[] = [
  { id: 'channels', label: 'Channels', dimension: AnalyticsDimension.Channel },
  {
    id: 'sources',
    label: 'Sources',
    dimension: AnalyticsDimension.ReferrerSource,
  },
  {
    id: 'campaigns',
    label: 'Campaigns',
    dimension: AnalyticsDimension.UtmCampaign,
  },
]

export function TrafficSourcesPanel(props: PanelProps) {
  return (
    <BreakdownPanel
      {...props}
      title="Traffic sources"
      description="Where visitors came from"
      tabs={SOURCE_TABS}
      renderLeading={(_entry, index, tabId) =>
        tabId === 'channels' ? <RowDot color={colorAt(index)} /> : undefined
      }
      rowColor={(_entry, index) => colorAt(index)}
    />
  )
}

const PAGE_TABS: BreakdownTab[] = [
  { id: 'top', label: 'Top pages', dimension: AnalyticsDimension.Page },
  {
    id: 'entry',
    label: 'Entry pages',
    dimension: AnalyticsDimension.EntryPage,
  },
  { id: 'exit', label: 'Exit pages', dimension: AnalyticsDimension.ExitPage },
]

export function PagesPanel(props: PanelProps) {
  return (
    <BreakdownPanel
      {...props}
      title="Pages"
      description="Most visited paths"
      tabs={PAGE_TABS}
      renderLeading={(_entry, index) => <RowRank index={index} />}
      mono
    />
  )
}

const LOCATION_TABS: BreakdownTab[] = [
  {
    id: 'countries',
    label: 'Countries',
    dimension: AnalyticsDimension.Country,
  },
  { id: 'regions', label: 'Regions', dimension: AnalyticsDimension.Region },
  { id: 'cities', label: 'Cities', dimension: AnalyticsDimension.City },
]

export function LocationsPanel(props: PanelProps) {
  return (
    <BreakdownPanel
      {...props}
      title="Locations"
      description="Where visitors are browsing from"
      tabs={LOCATION_TABS}
      renderLeading={(entry, _index, tabId) =>
        // Only `country` is an ISO-2 code; regions and cities are names.
        tabId === 'countries' ? <CountryFlag code={entry.value} /> : undefined
      }
    />
  )
}

const TECH_TABS: BreakdownTab[] = [
  { id: 'browsers', label: 'Browsers', dimension: AnalyticsDimension.Browser },
  {
    id: 'os',
    label: 'Operating systems',
    dimension: AnalyticsDimension.OperatingSystem,
  },
  { id: 'devices', label: 'Devices', dimension: AnalyticsDimension.Device },
]

export function TechnologyPanel(props: PanelProps) {
  return (
    <BreakdownPanel
      {...props}
      title="Technology"
      description="What visitors are browsing with"
      tabs={TECH_TABS}
      rowColor={(_entry, index) => colorAt(index)}
    />
  )
}

const COMPOSITION_TABS: BreakdownTab[] = [
  {
    id: 'composition',
    label: 'Traffic composition',
    dimension: AnalyticsDimension.TrafficType,
  },
]

export function TrafficCompositionPanel(props: PanelProps) {
  return (
    <BreakdownPanel
      {...props}
      title="Traffic composition"
      description="Human visitors versus bots"
      tabs={COMPOSITION_TABS}
      renderLeading={(_entry, index) => <RowDot color={colorAt(index)} />}
      rowColor={(_entry, index) => colorAt(index)}
    />
  )
}

const BOT_TABS: BreakdownTab[] = [
  { id: 'agents', label: 'Agents', dimension: AnalyticsDimension.BotName },
  {
    id: 'categories',
    label: 'Categories',
    dimension: AnalyticsDimension.BotCategory,
  },
]

export function BotsPanel(props: PanelProps) {
  return (
    <BreakdownPanel
      {...props}
      title="AI agents and crawlers"
      // botCategory has nine values and distinguishes an AI crawler from an AI
      // assistant, with a bare `crawler` fallback, so the copy does not promise
      // a clean AI-versus-crawler split.
      description="Named bots seen in this range, as classified by the API"
      tabs={BOT_TABS}
      emptyLabel="No bot traffic in this range"
    />
  )
}

/**
 * Visitor types come straight from the metric response. `newVisitors` and
 * `returningVisitors` sum to `visitors`, so the share is computed against that
 * denominator rather than issuing another request.
 */
export function VisitorTypesPanel({
  stats,
}: {
  stats: Models.AnalyticsMetric
}) {
  const t = useT()

  const rows = useMemo(
    () => [
      { label: 'New visitors', value: stats.newVisitors },
      { label: 'Returning visitors', value: stats.returningVisitors },
    ],
    [stats.newVisitors, stats.returningVisitors],
  )

  const denominator = stats.visitors
  const max = Math.max(...rows.map((row) => row.value), 0)
  const hasData = denominator > 0 || max > 0

  return (
    <div className="rounded-lg border border-border bg-card">
      <div className="border-b border-border px-4 py-2.5">
        <h3 className="text-[13px] font-semibold text-foreground">
          {t('Visitor types')}
        </h3>
        <p className="mt-0.5 text-[11px] text-muted-foreground">
          {t('Returning visitors were also seen in the preceding 180 days')}
        </p>
      </div>
      <div className="p-4">
        {!hasData ? (
          <p className="py-6 text-center text-[13px] text-muted-foreground">
            {t('No data in this range')}
          </p>
        ) : (
          <div className="space-y-0.5">
            {rows.map((row, index) => (
              <BreakdownRow
                key={row.label}
                label={t(row.label)}
                value={row.value}
                share={denominator > 0 ? (row.value / denominator) * 100 : 0}
                barPercent={max > 0 ? (row.value / max) * 100 : 0}
                leading={<RowDot color={colorAt(index)} />}
                color={colorAt(index)}
              />
            ))}
            <p className="pt-2 text-[11px] text-muted-foreground">
              {formatNumber(denominator)} {t('unique visitors in total')}
            </p>
          </div>
        )}
      </div>
    </div>
  )
}
