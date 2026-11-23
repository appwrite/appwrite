import type { UsageTopEndpoint } from '@/lib/usage/usage-events-common'
import type { UsageBreakdownItem } from '@/lib/usage/requests-breakdowns'
import { isUsageProjectResourceType } from '@/lib/usage/usage-resource-filters'

export const COMPUTE_EXECUTIONS_DESCRIPTION =
  'Function and site executions during the selected period. Each HTTP trigger, schedule run, event invocation, or site request counts as one execution.'

export const COMPUTE_GB_HOURS_DESCRIPTION =
  'Compute time during the selected period, measured in gigabyte-hours (GBH). Memory allocated to functions and sites multiplied by execution, request handling, and build duration.'

export const COMPUTE_FUNCTION_EXECUTIONS_DESCRIPTION =
  'Function executions during the selected period. Each HTTP trigger, schedule run, or event invocation counts as one execution.'

export const COMPUTE_SITE_EXECUTIONS_DESCRIPTION =
  'Site executions during the selected period. Each HTTP request served by your site counts toward execution usage.'

export const COMPUTE_FUNCTION_GB_HOURS_DESCRIPTION =
  'Function compute time during the selected period, measured in gigabyte-hours (GBH). Memory allocated to functions multiplied by execution and build duration.'

export const COMPUTE_SITE_GB_HOURS_DESCRIPTION =
  'Site compute time during the selected period, measured in gigabyte-hours (GBH). Memory allocated to sites multiplied by request handling and build duration.'

export const COMPUTE_FUNCTIONS_DOCS_HREF = '/docs/products/functions'
export const COMPUTE_SITES_DOCS_HREF = '/docs/products/sites'
export const COMPUTE_DOCS_HREF = COMPUTE_FUNCTIONS_DOCS_HREF

export const COMPUTE_RESOURCE_TYPES_BREAKDOWN_TITLE = 'Resource types'
export const COMPUTE_EXECUTIONS_BREAKDOWN_TITLE = 'Resource IDs'
export const COMPUTE_GB_HOURS_BREAKDOWN_TITLE = 'Resource IDs'
export const COMPUTE_EXECUTIONS_CHART_TITLE = 'Executions over time'
export const COMPUTE_FUNCTION_EXECUTIONS_BREAKDOWN_TITLE = 'Resource IDs'
export const COMPUTE_SITE_EXECUTIONS_BREAKDOWN_TITLE = 'Resource IDs'
export const COMPUTE_FUNCTION_GB_HOURS_BREAKDOWN_TITLE = 'Resource IDs'
export const COMPUTE_SITE_GB_HOURS_BREAKDOWN_TITLE = 'Resource IDs'

export function topConsumersToBreakdownItems(
  topConsumers: UsageTopEndpoint[],
): UsageBreakdownItem[] {
  return topConsumers.map((item) => {
    if (isUsageProjectResourceType(item.resourceType)) {
      return {
        id: item.id,
        label: 'project',
        count: item.count,
        resourceType: 'project',
      }
    }

    return {
      id: item.id,
      label: item.path,
      count: item.count,
      resourceId: item.path,
      resourceType: item.resourceType,
    }
  })
}
