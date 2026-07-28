import type { DateRange } from 'react-day-picker'
import {
  fetchProjectUsageEventBreakdown,
  type UsageBreakdownItem,
  type UsageEventBreakdownDimension,
} from '@/lib/usage/usage-events-common'
import { OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT } from '@/lib/usage/breakdown-limits'
import { REQUESTS_EVENT_METRICS } from '@/lib/usage/requests-events'

export type RequestsBreakdownSection = {
  dimension: UsageEventBreakdownDimension
  title: string
  description: string
  metricId: string
  labelVariant: 'mono' | 'default'
}

/** All listEvents dimensions supported for network.requests. */
export const REQUESTS_BREAKDOWN_SECTIONS: readonly RequestsBreakdownSection[] = [
  {
    dimension: 'path',
    title: 'Paths',
    description: 'API endpoint paths with the highest request volume.',
    metricId: 'breakdown-path',
    labelVariant: 'mono',
  },
  {
    dimension: 'method',
    title: 'HTTP methods',
    description: 'Request volume grouped by HTTP method.',
    metricId: 'breakdown-method',
    labelVariant: 'default',
  },
  {
    dimension: 'status',
    title: 'Status codes',
    description: 'Request volume grouped by HTTP response status.',
    metricId: 'breakdown-status',
    labelVariant: 'default',
  },
  {
    dimension: 'service',
    title: 'Services',
    description: 'Request volume grouped by Appwrite service segment.',
    metricId: 'breakdown-service',
    labelVariant: 'default',
  },
  {
    dimension: 'country',
    title: 'Countries',
    description: 'Request volume grouped by caller country.',
    metricId: 'breakdown-country',
    labelVariant: 'default',
  },
  {
    dimension: 'city',
    title: 'Cities',
    description: 'Request volume grouped by caller city.',
    metricId: 'breakdown-city',
    labelVariant: 'default',
  },
  {
    dimension: 'hostname',
    title: 'Hostnames',
    description: 'Request volume grouped by caller hostname.',
    metricId: 'breakdown-hostname',
    labelVariant: 'mono',
  },
  {
    dimension: 'ip',
    title: 'IP addresses',
    description: 'Request volume grouped by caller IP address.',
    metricId: 'breakdown-ip',
    labelVariant: 'mono',
  },
  {
    dimension: 'osName',
    title: 'Operating systems',
    description: 'Request volume grouped by client operating system.',
    metricId: 'breakdown-os',
    labelVariant: 'default',
  },
  {
    dimension: 'clientType',
    title: 'Client types',
    description: 'Request volume grouped by client type.',
    metricId: 'breakdown-client-type',
    labelVariant: 'default',
  },
  {
    dimension: 'clientName',
    title: 'Clients',
    description: 'Request volume grouped by client name.',
    metricId: 'breakdown-client-name',
    labelVariant: 'default',
  },
  {
    dimension: 'deviceName',
    title: 'Devices',
    description: 'Request volume grouped by device classification.',
    metricId: 'breakdown-device',
    labelVariant: 'default',
  },
  {
    dimension: 'sdk',
    title: 'SDKs',
    description: 'Request volume grouped by SDK and version.',
    metricId: 'breakdown-sdk',
    labelVariant: 'mono',
  },
  {
    dimension: 'resource',
    title: 'Resources',
    description: 'Request volume grouped by resource.',
    metricId: 'breakdown-resources',
    labelVariant: 'default',
  },
  {
    dimension: 'resourceType',
    title: 'Resource types',
    description: 'Request volume grouped by resource type.',
    metricId: 'breakdown-resource-type',
    labelVariant: 'default',
  },
] as const

export async function fetchProjectRequestsBreakdown(
  projectId: string,
  dateRange: DateRange | undefined,
  dimension: UsageEventBreakdownDimension,
  limit = OVERVIEW_ENDPOINT_BREAKDOWN_LIMIT,
  queries?: string[],
): Promise<UsageBreakdownItem[]> {
  return fetchProjectUsageEventBreakdown(
    projectId,
    REQUESTS_EVENT_METRICS[0],
    dateRange,
    dimension,
    limit,
    queries,
  )
}

export type { UsageBreakdownItem, UsageEventBreakdownDimension }
