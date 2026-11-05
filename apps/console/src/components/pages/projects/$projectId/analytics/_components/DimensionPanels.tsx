import { AnalyticsDimension } from '@appwrite.io/console'
import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  analyticsBreakdownQueryOptions,
  useAnalyticsProperty,
  useCountryLookups,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import { resolveCountryDisplayName } from '@/lib/locale/country-lookups'
import { isKnownBreakdownValue } from '@/lib/analytics/breakdown-values'
import { useT } from '@/lib/i18n/translate'
import {
  BreakdownBody,
  BreakdownHeader,
  BreakdownMessage,
  BreakdownPanel,
  BreakdownSkeleton,
  type BreakdownTab,
} from './BreakdownPanel'
import { useAnalyticsFilters } from './analytics-filters-context'
import type { AnalyticsFilter } from '@/lib/analytics/analytics-filters'
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

// Same dimensions for mobile apps, which report screen names instead of URLs.
const SCREEN_TABS: BreakdownTab[] = [
  { id: 'top', label: 'Top screens', dimension: AnalyticsDimension.Page },
  {
    id: 'entry',
    label: 'Entry screens',
    dimension: AnalyticsDimension.EntryPage,
  },
  {
    id: 'exit',
    label: 'Exit screens',
    dimension: AnalyticsDimension.ExitPage,
  },
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

export function PagesPanel({
  screens,
  ...props
}: PanelProps & {
  screens?: boolean
}) {
  // Cached by the page header already; used to turn paths into links.
  const { property } = useAnalyticsProperty(props.projectId, props.propertyId)
  return (
    <BreakdownPanel
      {...props}
      rowHref={
        screens
          ? undefined
          : (value, tabId) => pageRowHref(value, tabId, property?.domain)
      }
      cardId="pages"
      title={screens ? 'Screens' : 'Pages'}
      description={screens ? 'Most viewed screens' : 'Most visited paths'}
      tabs={screens ? SCREEN_TABS : PAGE_TABS}
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
  {
    id: 'browser-versions',
    label: 'Browser versions',
    dimension: AnalyticsDimension.BrowserVersion,
    group: 'Versions',
  },
  {
    id: 'os-versions',
    label: 'OS versions',
    dimension: AnalyticsDimension.OperatingSystemVersion,
    group: 'Versions',
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

const USER_TABS: BreakdownTab[] = [
  { id: 'users', label: 'Users', dimension: AnalyticsDimension.UserId },
]

/** Keeps a query's previous result across filter changes, never across properties. */
function samePropertyPlaceholder<T>(propertyId: string) {
  // Key index 3 is the property in analyticsBreakdownQueryOptions.
  return (previous: T | undefined, previousQuery?: { queryKey: readonly unknown[] }) =>
    previousQuery?.queryKey[3] === propertyId ? previous : undefined
}

/**
 * Shown when the property identifies users, or when user IDs arrive anyway
 * (sent by a server with an API key while the setting is off). Drives both the
 * Users card and the overview's Users tab.
 */
export function useShowUsersPanel(
  { projectId, propertyId, range }: PanelProps,
  filters: readonly AnalyticsFilter[],
) {
  const { property } = useAnalyticsProperty(projectId, propertyId)
  // Same key as the panel's own query, so it is one request. The previous
  // answer is kept while a new filter loads so the card doesn't blink out.
  const { data } = useQuery({
    ...analyticsBreakdownQueryOptions(
      projectId,
      propertyId,
      AnalyticsDimension.UserId,
      range,
      undefined,
      true,
      filters,
    ),
    placeholderData: samePropertyPlaceholder(propertyId),
  })
  return !!property?.identifyUsers || (data?.breakdown.length ?? 0) > 0
}

export function UsersPanel(props: PanelProps) {
  // No link to the user: an ID sent with an API key may not be a project user.
  return (
    <BreakdownPanel
      {...props}
      title="Users"
      description="Identified users, ranked by visitors"
      info="users"
      tabs={USER_TABS}
      mono
      emptyLabel="No identified users in this range"
    />
  )
}

const MODEL_TABS: BreakdownTab[] = [
  {
    id: 'models',
    label: 'Models',
    dimension: AnalyticsDimension.Props,
    propKey: 'model',
  },
  {
    id: 'tools',
    label: 'Tools',
    dimension: AnalyticsDimension.Props,
    propKey: 'tool',
  },
]

/**
 * The `model` and `tool` props of an agent's `model_called` and
 * `tool_called` events, counted in calls. Hidden until either key arrives.
 */
export function ModelsPanel(props: PanelProps) {
  const { filters } = useAnalyticsFilters()
  // Same key as the Event properties card's query, so it is one request.
  const { data } = useQuery({
    ...analyticsBreakdownQueryOptions(
      props.projectId,
      props.propertyId,
      AnalyticsDimension.Props,
      props.range,
      undefined,
      true,
      filters,
    ),
    placeholderData: samePropertyPlaceholder(props.propertyId),
  })
  const tabs = useMemo(() => {
    const keys = new Set(data?.breakdown.map((entry) => entry.value))
    return MODEL_TABS.filter((tab) => keys.has(tab.propKey!))
  }, [data])

  if (tabs.length === 0) return null

  // The API returns its top rows by visitors, which an agent barely has, so a
  // busy model can fall outside them until the API can order by events.
  return (
    <BreakdownPanel
      {...props}
      cardId="models"
      title="Models and tools"
      description="Calls by model and by tool"
      tabs={tabs}
      valueKey="events"
      mono
      emptyLabel="No calls in this range"
    />
  )
}

/** Prop keys shown as tabs before the rest move under "More". */
const PROP_TABS_INLINE = 4

/**
 * Custom event props: one tab per prop key (ranked by visitors), each ranking
 * that key's values. Clicking a value filters on `props.<key>`.
 */
export function EventPropertiesPanel(props: PanelProps) {
  const t = useT()
  const { filters } = useAnalyticsFilters()
  // The previous keys stay while a new filter loads, so the tabs (and the
  // selected one) survive a click on a value.
  const { data, isLoading, error } = useQuery({
    ...analyticsBreakdownQueryOptions(
      props.projectId,
      props.propertyId,
      AnalyticsDimension.Props,
      props.range,
      undefined,
      true,
      filters,
    ),
    placeholderData: samePropertyPlaceholder(props.propertyId),
  })
  const keys = data?.breakdown

  const tabs = useMemo(
    () =>
      (keys ?? [])
        .filter((entry) => isKnownBreakdownValue(entry.value))
        .map(
          (entry, index): BreakdownTab => ({
            id: `prop:${entry.value}`,
            label: entry.value!,
            dimension: AnalyticsDimension.Props,
            propKey: entry.value!,
            group: index >= PROP_TABS_INLINE ? 'More' : undefined,
          }),
        ),
    [keys],
  )

  const title = 'Event properties'
  const description = 'Values of the props sent with your events'

  if (tabs.length > 0) {
    return (
      <BreakdownPanel
        {...props}
        title={title}
        description={description}
        tabs={tabs}
        mono
      />
    )
  }

  return (
    <div className="flex flex-col rounded-lg border border-border bg-card">
      <BreakdownHeader title={title} description={description} />
      <BreakdownBody canShowMore={false} onShowMore={() => {}}>
        {error ? (
          <BreakdownMessage>
            {error instanceof Error
              ? error.message
              : t('Could not load analytics data')}
          </BreakdownMessage>
        ) : isLoading ? (
          <BreakdownSkeleton />
        ) : (
          <BreakdownMessage>
            {t('No event props in this range')}
          </BreakdownMessage>
        )}
      </BreakdownBody>
    </div>
  )
}
