import {
  generateMockUsageData,
  type UsageCategory,
} from './data'
import {
  BANDWIDTH_USAGE_CATEGORY,
  buildBandwidthUsageCategoryMetrics,
  DATABASES_USAGE_CATEGORY,
  buildDatabasesUsageCategoryMetrics,
  REALTIME_USAGE_CATEGORY,
  buildRealtimeUsageCategoryMetrics,
  AUTH_USAGE_CATEGORY,
  buildAuthUsageCategoryMetrics,
  COMPUTE_USAGE_CATEGORY,
  buildComputeUsageCategoryMetrics,
  REQUESTS_USAGE_CATEGORY,
  buildRequestsUsageCategoryMetrics,
} from './usage-live-categories'

export const DEFAULT_USAGE_CATEGORY_ID = 'requests'

export type UsageNavGroupId = 'resources' | 'products'

export type UsageNavGroupConfig = {
  id: UsageNavGroupId
  label: string
  categoryIds: readonly string[]
}

/** Sidebar grouping for usage categories. */
export const USAGE_NAV_GROUPS: readonly UsageNavGroupConfig[] = [
  {
    id: 'resources',
    label: 'Resources',
    categoryIds: ['requests', 'bandwidth', 'compute'],
  },
  {
    id: 'products',
    label: 'Products',
    categoryIds: [
      'auth',
      'databases',
      'storage',
      'realtime',
      'messaging',
    ],
  },
]

export type UsageNavGroup = UsageNavGroupConfig & {
  categories: UsageCategory[]
}

export function getUsageNavGroups(
  plan: 'free' | 'pro' | 'custom' = 'pro',
): UsageNavGroup[] {
  const categories = getUsageCategories(plan)
  const categoryById = new Map(categories.map((category) => [category.id, category]))

  return USAGE_NAV_GROUPS.map((group) => ({
    ...group,
    categories: group.categoryIds
      .map((categoryId) => categoryById.get(categoryId))
      .filter((category): category is UsageCategory => category != null),
  })).filter((group) => group.categories.length > 0)
}

export function insertBandwidthCategory(
  categories: UsageCategory[],
): UsageCategory[] {
  const bandwidthCategory: UsageCategory = {
    ...BANDWIDTH_USAGE_CATEGORY,
    metrics: buildBandwidthUsageCategoryMetrics(),
  }

  return categories.map((category) =>
    category.id === 'bandwidth' ? bandwidthCategory : category,
  )
}

export function insertRequestsCategory(
  categories: UsageCategory[],
): UsageCategory[] {
  const requestsCategory: UsageCategory = {
    ...REQUESTS_USAGE_CATEGORY,
    metrics: buildRequestsUsageCategoryMetrics(),
  }

  const bandwidthIndex = categories.findIndex(
    (category) => category.id === 'bandwidth',
  )
  if (bandwidthIndex === -1) {
    return [...categories, requestsCategory]
  }

  return [
    ...categories.slice(0, bandwidthIndex),
    requestsCategory,
    ...categories.slice(bandwidthIndex),
  ]
}

export function insertDatabasesCategory(
  categories: UsageCategory[],
): UsageCategory[] {
  const databasesCategory: UsageCategory = {
    ...DATABASES_USAGE_CATEGORY,
    metrics: buildDatabasesUsageCategoryMetrics(),
  }

  return categories.map((category) =>
    category.id === 'databases' ? databasesCategory : category,
  )
}

export function insertRealtimeCategory(
  categories: UsageCategory[],
): UsageCategory[] {
  const realtimeCategory: UsageCategory = {
    ...REALTIME_USAGE_CATEGORY,
    metrics: buildRealtimeUsageCategoryMetrics(),
  }

  return categories.map((category) =>
    category.id === 'realtime' ? realtimeCategory : category,
  )
}

export function insertComputeCategory(
  categories: UsageCategory[],
): UsageCategory[] {
  const computeCategory: UsageCategory = {
    ...COMPUTE_USAGE_CATEGORY,
    metrics: buildComputeUsageCategoryMetrics(),
  }

  return categories.map((category) =>
    category.id === 'compute' ? computeCategory : category,
  )
}

export function insertAuthCategory(categories: UsageCategory[]): UsageCategory[] {
  const authCategory: UsageCategory = {
    ...AUTH_USAGE_CATEGORY,
    metrics: buildAuthUsageCategoryMetrics(),
  }

  return categories.map((category) =>
    category.id === 'auth' ? authCategory : category,
  )
}

export function getUsageCategories(
  plan: 'free' | 'pro' | 'custom' = 'pro',
): UsageCategory[] {
  const usageData = generateMockUsageData(plan)
  return insertAuthCategory(
    insertComputeCategory(
      insertRealtimeCategory(
        insertDatabasesCategory(
          insertBandwidthCategory(insertRequestsCategory(usageData.categories)),
        ),
      ),
    ),
  )
}

export function findUsageCategory(
  categories: UsageCategory[],
  categoryId: string,
): UsageCategory | undefined {
  return categories.find((category) => category.id === categoryId)
}

export function isValidUsageCategory(
  categoryId: string,
  plan: 'free' | 'pro' | 'custom' = 'pro',
): boolean {
  return findUsageCategory(getUsageCategories(plan), categoryId) != null
}

export function getDefaultUsageCategoryId(): string {
  return DEFAULT_USAGE_CATEGORY_ID
}

export function resolveUsageCategoryId(
  categoryId: string | undefined,
  plan: 'free' | 'pro' | 'custom' = 'pro',
): string {
  if (categoryId && isValidUsageCategory(categoryId, plan)) {
    return categoryId
  }

  return DEFAULT_USAGE_CATEGORY_ID
}

/** Read category id from a usage URL (layout route does not include $categoryId in params). */
export function getUsageCategoryIdFromPathname(pathname: string): string | undefined {
  const parts = pathname.split('/').filter(Boolean)
  const usageIndex = parts.findIndex((part) => part === 'usage')
  if (usageIndex < 0) return undefined
  return parts[usageIndex + 1] ?? undefined
}
