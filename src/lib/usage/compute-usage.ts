import type { UsageTopEndpoint } from '@/lib/usage/usage-events-common'
import type { UsageBreakdownItem } from '@/lib/usage/requests-breakdowns'

export const COMPUTE_EXECUTIONS_DESCRIPTION =
  'Function and site executions during the selected period. Each HTTP trigger, schedule run, or event invocation counts as one execution.'

export const COMPUTE_GB_HOURS_DESCRIPTION =
  'Compute time during the selected period, measured in gigabyte-hours (GBH). Memory allocated to functions and sites multiplied by execution duration.'

export const COMPUTE_DOCS_HREF = '/docs/products/functions'

export const COMPUTE_EXECUTIONS_BREAKDOWN_TITLE = 'Top executed functions'
export const COMPUTE_EXECUTIONS_CHART_TITLE = 'Function executions over time'
export const COMPUTE_GB_HOURS_BREAKDOWN_TITLE = 'Top compute consumers'

export function topConsumersToBreakdownItems(
  topConsumers: UsageTopEndpoint[],
): UsageBreakdownItem[] {
  return topConsumers.map((item) => ({
    id: item.id,
    label: item.path,
    count: item.count,
  }))
}
