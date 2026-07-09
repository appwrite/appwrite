import type { UsageEventBreakdownDimension } from '@/lib/usage/usage-events-common'

export const USAGE_RESOURCES_BREAKDOWN_TITLE = 'Resources'

export const USAGE_RESOURCE_ID_VIEW_LABEL = 'Resource ID'
export const USAGE_RESOURCE_TYPE_VIEW_LABEL = 'Resource type'

export type UsageResourceBreakdownDimension = Extract<
  UsageEventBreakdownDimension,
  'resourceId' | 'resourceType'
>

export type UsageResourceBreakdownView = {
  dimension: UsageResourceBreakdownDimension
  label: string
  labelVariant: 'mono' | 'default'
}

export const USAGE_RESOURCE_BREAKDOWN_VIEWS: readonly UsageResourceBreakdownView[] =
  [
    {
      dimension: 'resourceId',
      label: USAGE_RESOURCE_ID_VIEW_LABEL,
      labelVariant: 'mono',
    },
    {
      dimension: 'resourceType',
      label: USAGE_RESOURCE_TYPE_VIEW_LABEL,
      labelVariant: 'default',
    },
  ] as const

export function splitUsageBreakdownEntries<
  T extends { section: { dimension: UsageEventBreakdownDimension } },
>(
  entries: T[],
): {
  standardEntries: T[]
  resourceIdEntry?: T
  resourceTypeEntry?: T
} {
  return {
    standardEntries: entries.filter(
      (entry) =>
        entry.section.dimension !== 'resourceId' &&
        entry.section.dimension !== 'resourceType',
    ),
    resourceIdEntry: entries.find(
      (entry) => entry.section.dimension === 'resourceId',
    ),
    resourceTypeEntry: entries.find(
      (entry) => entry.section.dimension === 'resourceType',
    ),
  }
}
