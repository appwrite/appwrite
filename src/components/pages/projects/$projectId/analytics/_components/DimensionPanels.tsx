import { AnalyticsDimension } from '@appwrite.io/console'
import {
  useAnalyticsProperty,
  useCountryLookups,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import { resolveCountryDisplayName } from '@/lib/locale/country-lookups'
import { BreakdownPanel, type BreakdownTab } from './BreakdownPanel'
import { BrowserIcon, ChannelIcon, CountryFlag, SourceFavicon } from './BreakdownRow'

// Bars stay neutral (the BreakdownRow default), like Usage's breakdowns:
// rows are told apart by their icon and label, not a colour per rank.

type PanelProps = {
  projectId: string
  propertyId: string
  range: AnalyticsRange
}

const CAMPAIGNS_GROUP = 'Campaigns'
const NO_CAMPAIGN_TRAFFIC = 'No tagged campaign traffic in this range'

/**
 * Channels and referrers, with every UTM parameter behind one "Campaigns"
 * menu tab. "Sources" is the referrer; "UTM sources" is `utm_source`.
 */
const SOURCE_TABS: BreakdownTab[] = [
  { id: 'channels', label: 'Channels', dimension: AnalyticsDimension.Channel },
  {
    id: 'sources',
    label: 'Sources',
    dimension: AnalyticsDimension.ReferrerSource,
  },
  ...(
    [
      ['utm-campaigns', 'UTM campaigns', AnalyticsDimension.UtmCampaign],
      ['utm-sources', 'UTM sources', AnalyticsDimension.UtmSource],
      ['utm-mediums', 'UTM mediums', AnalyticsDimension.UtmMedium],
      ['utm-contents', 'UTM contents', AnalyticsDimension.UtmContent],
      ['utm-terms', 'UTM terms', AnalyticsDimension.UtmTerm],
    ] as const
  ).map(
    ([id, label, dimension]): BreakdownTab => ({
      id,
      label,
      dimension,
      group: CAMPAIGNS_GROUP,
      emptyLabel: NO_CAMPAIGN_TRAFFIC,
    }),
  ),
]

export function TrafficSourcesPanel(props: PanelProps) {
  return (
    <BreakdownPanel
      {...props}
      cardId="sources"
      title="Traffic sources"
      description="Where visitors came from, by channel, referrer and campaign"
      info="channels"
      tabs={SOURCE_TABS}
      // Referrers that are domains (github.com) link out; names (Google,
      // direct) and UTM values don't.
      rowHref={(value, tabId) =>
        tabId === 'sources' && /^[^\s/]+\.[a-z]{2,}(\/|$)/i.test(bareHost(value))
          ? `https://${bareHost(value)}`
          : undefined
      }
      renderLeading={(entry, _index, tabId) =>
        tabId === 'channels' ? (
          <ChannelIcon value={entry.value} />
        ) : tabId === 'sources' || tabId === 'utm-sources' ? (
          // Referrer and utm_source values are mostly domains or site names.
          <SourceFavicon value={entry.value} />
        ) : undefined
      }
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
  // Useful when one property tracks several domains or subdomains.
  { id: 'hosts', label: 'Hostnames', dimension: AnalyticsDimension.Hostname },
]

/** `example.com`, `https://example.com/` → `example.com`. */
function bareHost(value: string): string {
  return value.trim().replace(/^https?:\/\//i, '').replace(/\/+$/, '')
}

/**
 * Link for a page path or hostname row. Paths resolve against the property's
 * domain; without one there's nowhere to send a bare path, so no link.
 */
export function pageRowHref(
  value: string,
  tabId: string,
  domain: string | null | undefined,
): string | undefined {
  const trimmed = value.trim()
  if (!trimmed) return undefined
  if (/^https?:\/\//i.test(trimmed)) return trimmed
  if (tabId === 'hosts') return `https://${bareHost(trimmed)}`
  if (trimmed.startsWith('/')) {
    return domain ? `https://${bareHost(domain)}${trimmed}` : undefined
  }
  // `host.tld/path` without a scheme.
  if (/^[^\s/]+\.[^\s/]+(\/|$)/.test(trimmed)) return `https://${trimmed}`
  return undefined
}

export function PagesPanel(props: PanelProps) {
  // Cached by the page header already; used to turn paths into links.
  const { property } = useAnalyticsProperty(props.projectId, props.propertyId)
  return (
    <BreakdownPanel
      {...props}
      rowHref={(value, tabId) => pageRowHref(value, tabId, property?.domain)}
      cardId="pages"
      title="Pages"
      description="Most visited paths"
      tabs={PAGE_TABS}
      // Rank numbers come from the panel (and the modal's own rank column),
      // not renderLeading, so they're never drawn twice.
      ranked
      mono
    />
  )
}

const LOCATION_TABS: BreakdownTab[] = [
  // Same `country` query as the Countries tab, drawn as a choropleth.
  {
    id: 'map',
    label: 'Map',
    dimension: AnalyticsDimension.Country,
    display: 'map',
  },
  {
    id: 'countries',
    label: 'Countries',
    dimension: AnalyticsDimension.Country,
  },
  { id: 'regions', label: 'Regions', dimension: AnalyticsDimension.Region },
  { id: 'cities', label: 'Cities', dimension: AnalyticsDimension.City },
]

export function LocationsPanel(props: PanelProps) {
  // Same locale list Usage uses to print country names beside the flags.
  const { lookups: countryLookups } = useCountryLookups()

  return (
    <BreakdownPanel
      {...props}
      cardId="locations"
      title="Locations"
      description="Where visitors are browsing from"
      tabs={LOCATION_TABS}
      renderLeading={(entry, _index, tabId) =>
        // Only `country` is an ISO-2 code; regions and cities are names.
        tabId === 'countries' ? <CountryFlag code={entry.value} /> : undefined
      }
      formatLabel={(value, tabId) =>
        (tabId === 'countries' || tabId === 'map') && countryLookups
          ? resolveCountryDisplayName(value, countryLookups)
          : value
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
  {
    id: 'screens',
    label: 'Screen sizes',
    dimension: AnalyticsDimension.ScreenSize,
  },
]

export function TechnologyPanel(props: PanelProps) {
  return (
    <BreakdownPanel
      {...props}
      cardId="technology"
      title="Technology"
      description="What visitors are browsing with"
      tabs={TECH_TABS}
      renderLeading={(entry, _index, tabId) =>
        tabId === 'browsers' ? <BrowserIcon name={entry.value} /> : undefined
      }
    />
  )
}

// Human vs bot composition lives in the full-width `TrafficSplit` bar.

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
      cardId="bots"
      title="AI agents and crawlers"
      // botCategory has nine values and distinguishes an AI crawler from an AI
      // assistant, with a bare `crawler` fallback, so the copy does not promise
      // a clean AI-versus-crawler split.
      description="Named bots seen in this range, as classified by the API"
      info="bots"
      tabs={BOT_TABS}
      emptyLabel="No bot traffic in this range"
    />
  )
}
