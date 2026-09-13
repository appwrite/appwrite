import { AnalyticsDimension } from '@appwrite.io/console'
import type { AnalyticsRange } from '@/lib/react-query/hooks'
import { BreakdownPanel, type BreakdownTab } from './BreakdownPanel'
import { CountryFlag, RowDot, RowRank } from './BreakdownRow'

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
