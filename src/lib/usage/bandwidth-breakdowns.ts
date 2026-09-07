import type { DateRange } from 'react-day-picker'
import {
  fetchProjectUsageEventBreakdown,
  mergeUsageBreakdownItems,
  type UsageBreakdownItem,
  type UsageEventBreakdownDimension,
} from '@/lib/usage/usage-events-common'
import { BANDWIDTH_EVENT_METRICS } from '@/lib/usage/bandwidth-events'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'
import {
  REQUESTS_BREAKDOWN_SECTIONS,
  type RequestsBreakdownSection,
} from '@/lib/usage/requests-breakdowns'

export type BandwidthBreakdownSection = RequestsBreakdownSection

type RequestsBreakdownDimension =
  (typeof REQUESTS_BREAKDOWN_SECTIONS)[number]['dimension']

/**
 * Same cards and order as requests: request shape → caller identity →
 * location → network (premium) → client software → resource. Copy is
 * bandwidth-specific; titles, dimensions, and metric IDs stay shared.
 */
const BANDWIDTH_BREAKDOWN_DESCRIPTIONS: Record<
  RequestsBreakdownDimension,
  string
> = {
  path: 'Endpoint paths with the highest bandwidth consumption.',
  method: 'Bandwidth grouped by HTTP method.',
  status: 'Bandwidth grouped by HTTP response status.',
  service: 'Bandwidth grouped by Appwrite service segment.',
  hostname: 'Bandwidth grouped by caller hostname.',
  ip: 'Bandwidth grouped by caller IP address.',
  country: 'Bandwidth grouped by caller country.',
  city: 'Bandwidth grouped by caller city.',
  isp: 'Bandwidth grouped by caller internet service provider.',
  autonomousSystemNumber:
    'Bandwidth grouped by caller autonomous system number.',
  autonomousSystemOrganization:
    'Bandwidth grouped by caller autonomous system organization.',
  connectionType: 'Bandwidth grouped by caller connection type.',
  connectionUsageType: 'Bandwidth grouped by caller connection usage type.',
  connectionOrganization:
    'Bandwidth grouped by caller connection organization.',
  osName: 'Bandwidth grouped by client operating system.',
  clientType: 'Bandwidth grouped by client type.',
  clientName: 'Bandwidth grouped by client name.',
  deviceName: 'Bandwidth grouped by device classification.',
  sdk: 'Bandwidth grouped by SDK and version.',
  resource: 'Bandwidth grouped by resource.',
  resourceType: 'Bandwidth grouped by resource type.',
}

export const BANDWIDTH_BREAKDOWN_SECTIONS: readonly BandwidthBreakdownSection[] =
  REQUESTS_BREAKDOWN_SECTIONS.map((section) => ({
    ...section,
    description: BANDWIDTH_BREAKDOWN_DESCRIPTIONS[section.dimension],
  }))

export async function fetchProjectBandwidthBreakdown(
  projectId: string,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension,
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
  queries?: string[],
): Promise<UsageBreakdownItem[]> {
  const breakdowns = await Promise.all(
    BANDWIDTH_EVENT_METRICS.map((metric) =>
      fetchProjectUsageEventBreakdown(
        projectId,
        metric,
        dateRange,
        dimension,
        limit,
        queries,
      ),
    ),
  )

  return mergeUsageBreakdownItems(breakdowns, limit)
}

export type { UsageBreakdownItem, UsageEventBreakdownDimension }
