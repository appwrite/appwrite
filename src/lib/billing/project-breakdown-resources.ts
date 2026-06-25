import type { Models } from '@appwrite.io/console'

export type BillingProjectResourceFormat = 'bytes' | 'number' | 'sms'

export type BillingProjectResourceCategory =
  | 'network'
  | 'storage'
  | 'auth'
  | 'databases'
  | 'dedicated-databases'
  | 'compute'
  | 'avatars'
  | 'realtime'

export type BillingProjectResourceMapping = {
  name: string
  format: BillingProjectResourceFormat
  planKey: string
  category: BillingProjectResourceCategory
  showLimit?: boolean
  /** Hide rows with zero usage and zero cost (for billable add-ons). */
  showOnlyWhenUsed?: boolean
}

export type BillingProjectResourceItem = {
  resourceId: string
  name: string
  usage: number
  limit: number | null
  cost: number
  formatType: BillingProjectResourceFormat
  showLimit?: boolean
  category: BillingProjectResourceCategory
}

export type BillingProjectResourceCategoryGroup = {
  id: BillingProjectResourceCategory
  label: string
  resources: BillingProjectResourceItem[]
}

export const BILLING_PROJECT_RESOURCE_CATEGORY_ORDER: BillingProjectResourceCategory[] =
  [
    'network',
    'storage',
    'auth',
    'databases',
    'dedicated-databases',
    'compute',
    'avatars',
    'realtime',
  ]

export const BILLING_PROJECT_RESOURCE_CATEGORY_LABELS: Record<
  BillingProjectResourceCategory,
  string
> = {
  network: 'Network',
  storage: 'Storage',
  auth: 'Auth',
  databases: 'Databases',
  'dedicated-databases': 'Dedicated databases',
  compute: 'Compute',
  avatars: 'Avatars',
  realtime: 'Realtime',
}

type PlanUsageNameMap = Record<string, { name?: string } | undefined>

function getBillingResourceLabel(
  resourceId: string,
  defaultName: string,
  plan: Models.BillingPlan | null | undefined,
): string {
  const usage = plan?.usage as PlanUsageNameMap | undefined
  const fromPlan = usage?.[resourceId]?.name?.trim()
  return fromPlan || defaultName
}

const DEDICATED_DB_PROJECT_RESOURCES: Record<string, BillingProjectResourceMapping> =
  {
    dedicatedDbSpecificationCost: {
      name: 'Dedicated databases',
      format: 'number',
      planKey: 'dedicatedDbSpecificationCost',
      category: 'dedicated-databases',
      showLimit: false,
      showOnlyWhenUsed: true,
    },
    dedicatedDbStorage: {
      name: 'Dedicated database storage',
      format: 'bytes',
      planKey: 'dedicatedDbStorage',
      category: 'dedicated-databases',
      showLimit: false,
      showOnlyWhenUsed: true,
    },
    dedicatedDbBandwidth: {
      name: 'Dedicated database bandwidth',
      format: 'bytes',
      planKey: 'dedicatedDbBandwidth',
      category: 'dedicated-databases',
      showLimit: false,
      showOnlyWhenUsed: true,
    },
    dedicatedDbHaReplica: {
      name: 'HA replicas',
      format: 'number',
      planKey: 'dedicatedDbHaReplica',
      category: 'dedicated-databases',
      showLimit: false,
      showOnlyWhenUsed: true,
    },
    dedicatedDbCrossRegionReplica: {
      name: 'Cross-region replicas',
      format: 'number',
      planKey: 'dedicatedDbCrossRegionReplica',
      category: 'dedicated-databases',
      showLimit: false,
      showOnlyWhenUsed: true,
    },
    dedicatedDbCrossRegion: {
      name: 'Cross-region transfer',
      format: 'number',
      planKey: 'dedicatedDbCrossRegion',
      category: 'dedicated-databases',
      showLimit: false,
      showOnlyWhenUsed: true,
    },
    dedicatedDbPitr: {
      name: 'Point-in-time recovery',
      format: 'number',
      planKey: 'dedicatedDbPitr',
      category: 'dedicated-databases',
      showLimit: false,
      showOnlyWhenUsed: true,
    },
    dedicatedDbExtensions: {
      name: 'Database extensions',
      format: 'number',
      planKey: 'dedicatedDbExtensions',
      category: 'dedicated-databases',
      showLimit: false,
      showOnlyWhenUsed: true,
    },
  }

const BASE_PROJECT_RESOURCES: Record<string, BillingProjectResourceMapping> = {
  bandwidth: {
    name: 'Bandwidth',
    format: 'bytes',
    planKey: 'bandwidth',
    category: 'network',
  },
  storage: {
    name: 'Storage',
    format: 'bytes',
    planKey: 'storage',
    category: 'storage',
  },
  mau: {
    name: 'MAU',
    format: 'number',
    planKey: 'users',
    category: 'auth',
  },
  databasesReads: {
    name: 'Database reads',
    format: 'number',
    planKey: 'databaseReads',
    category: 'databases',
  },
  databasesWrites: {
    name: 'Database writes',
    format: 'number',
    planKey: 'databaseWrites',
    category: 'databases',
  },
  executions: {
    name: 'Executions',
    format: 'number',
    planKey: 'executions',
    category: 'compute',
  },
  imageTransformations: {
    name: 'Image transformations',
    format: 'number',
    planKey: 'imageTransformations',
    category: 'storage',
  },
  screenshotsGenerated: {
    name: 'Screenshots generated',
    format: 'number',
    planKey: 'screenshotsGenerated',
    category: 'avatars',
  },
  GBHours: {
    name: 'GB-hours',
    format: 'number',
    planKey: 'gbHours',
    category: 'compute',
  },
  realtime: {
    name: 'Realtime connections',
    format: 'number',
    planKey: 'realtime',
    category: 'realtime',
  },
  realtimeMessages: {
    name: 'Realtime messages',
    format: 'number',
    planKey: 'realtimeMessages',
    category: 'realtime',
  },
  realtimeBandwidth: {
    name: 'Realtime bandwidth',
    format: 'bytes',
    planKey: 'realtimeBandwidth',
    category: 'realtime',
    showLimit: false,
  },
  authPhone: {
    name: 'Phone OTP',
    format: 'sms',
    planKey: 'authPhone',
    category: 'auth',
  },
}

/** Display order for resources within each billing category. */
export const BILLING_PROJECT_RESOURCE_ID_ORDER: string[] = [
  'bandwidth',
  'storage',
  'imageTransformations',
  'mau',
  'authPhone',
  'databasesReads',
  'databasesWrites',
  ...Object.keys(DEDICATED_DB_PROJECT_RESOURCES),
  'executions',
  'GBHours',
  'screenshotsGenerated',
  'realtime',
  'realtimeMessages',
  'realtimeBandwidth',
]

function getBillingProjectResourceSortIndex(resourceId: string): number {
  const index = BILLING_PROJECT_RESOURCE_ID_ORDER.indexOf(resourceId)
  return index === -1 ? BILLING_PROJECT_RESOURCE_ID_ORDER.length : index
}

export function getBillingProjectResourceIdMap(
  plan: Models.BillingPlan | null | undefined,
): Record<string, BillingProjectResourceMapping> {
  return {
    ...BASE_PROJECT_RESOURCES,
    ...Object.fromEntries(
      Object.entries(DEDICATED_DB_PROJECT_RESOURCES).map(
        ([resourceId, mapping]) => [
          resourceId,
          {
            ...mapping,
            name: getBillingResourceLabel(resourceId, mapping.name, plan),
          },
        ],
      ),
    ),
  }
}

export function groupBillingProjectResources(
  resources: BillingProjectResourceItem[],
): BillingProjectResourceCategoryGroup[] {
  const byCategory = new Map<
    BillingProjectResourceCategory,
    BillingProjectResourceItem[]
  >()

  for (const resource of resources) {
    const existing = byCategory.get(resource.category) ?? []
    existing.push(resource)
    byCategory.set(resource.category, existing)
  }

  return BILLING_PROJECT_RESOURCE_CATEGORY_ORDER.filter(
    (categoryId) => (byCategory.get(categoryId)?.length ?? 0) > 0,
  ).map((categoryId) => ({
    id: categoryId,
    label: BILLING_PROJECT_RESOURCE_CATEGORY_LABELS[categoryId],
    resources: byCategory
      .get(categoryId)!
      .sort(
        (a, b) =>
          getBillingProjectResourceSortIndex(a.resourceId) -
          getBillingProjectResourceSortIndex(b.resourceId),
      ),
  }))
}
