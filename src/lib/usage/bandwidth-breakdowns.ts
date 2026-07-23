import type { DateRange } from 'react-day-picker'
import {
  fetchProjectUsageEventBreakdown,
  mergeUsageBreakdownItems,
  type UsageBreakdownItem,
  type UsageEventBreakdownDimension,
} from '@/lib/usage/usage-events-common'
import { BANDWIDTH_EVENT_METRICS } from '@/lib/usage/bandwidth-events'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'

export type BandwidthBreakdownSection = {
  dimension: UsageEventBreakdownDimension
  title: string
  description: string
  metricId: string
  labelVariant: 'mono' | 'default'
}

/** All listEvents dimensions supported for network inbound/outbound bandwidth. */
export const BANDWIDTH_BREAKDOWN_SECTIONS: readonly BandwidthBreakdownSection[] = [
  {
    dimension: 'path',
    title: 'Paths',
    description: 'Endpoint paths with the highest bandwidth consumption.',
    metricId: 'breakdown-path',
    labelVariant: 'mono',
  },
  {
    dimension: 'method',
    title: 'HTTP methods',
    description: 'Bandwidth grouped by HTTP method.',
    metricId: 'breakdown-method',
    labelVariant: 'default',
  },
  {
    dimension: 'status',
    title: 'Status codes',
    description: 'Bandwidth grouped by HTTP response status.',
    metricId: 'breakdown-status',
    labelVariant: 'default',
  },
  {
    dimension: 'service',
    title: 'Services',
    description: 'Bandwidth grouped by Appwrite service segment.',
    metricId: 'breakdown-service',
    labelVariant: 'default',
  },
  {
    dimension: 'country',
    title: 'Countries',
    description: 'Bandwidth grouped by caller country.',
    metricId: 'breakdown-country',
    labelVariant: 'default',
  },
  {
    dimension: 'city',
    title: 'Cities',
    description: 'Bandwidth grouped by caller city.',
    metricId: 'breakdown-city',
    labelVariant: 'default',
  },
  {
    dimension: 'hostname',
    title: 'Hostnames',
    description: 'Bandwidth grouped by caller hostname.',
    metricId: 'breakdown-hostname',
    labelVariant: 'mono',
  },
  {
    dimension: 'ip',
    title: 'IP addresses',
    description: 'Bandwidth grouped by caller IP address.',
    metricId: 'breakdown-ip',
    labelVariant: 'mono',
  },
  {
    dimension: 'osName',
    title: 'Operating systems',
    description: 'Bandwidth grouped by client operating system.',
    metricId: 'breakdown-os',
    labelVariant: 'default',
  },
  {
    dimension: 'clientType',
    title: 'Client types',
    description: 'Bandwidth grouped by client type.',
    metricId: 'breakdown-client-type',
    labelVariant: 'default',
  },
  {
    dimension: 'clientName',
    title: 'Clients',
    description: 'Bandwidth grouped by client name.',
    metricId: 'breakdown-client-name',
    labelVariant: 'default',
  },
  {
    dimension: 'deviceName',
    title: 'Devices',
    description: 'Bandwidth grouped by device classification.',
    metricId: 'breakdown-device',
    labelVariant: 'default',
  },
  {
    dimension: 'sdk',
    title: 'SDKs',
    description: 'Bandwidth grouped by SDK and version.',
    metricId: 'breakdown-sdk',
    labelVariant: 'mono',
  },
  {
    dimension: 'resource',
    title: 'Resources',
    description: 'Bandwidth grouped by resource.',
    metricId: 'breakdown-resources',
    labelVariant: 'default',
  },
  {
    dimension: 'resourceType',
    title: 'Resource types',
    description: 'Bandwidth grouped by resource type.',
    metricId: 'breakdown-resource-type',
    labelVariant: 'default',
  },
] as const

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
