import type { Models } from '@appwrite.io/console'
import {
  getSpecOptionById,
  mapDedicatedDatabaseSpecifications,
  type SpecOption,
} from '@/lib/database-specs'

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
  | 'addons'

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
  /** Plan limit is -1 (disabled). Show upgrade CTA instead of usage. */
  requiresUpgrade?: boolean
  /** Override default usage formatting (e.g. dedicated DB units). */
  usageLabel?: string
  usageDescription?: string
}

export type BillingProjectResourceCategoryGroup = {
  id: BillingProjectResourceCategory
  label: string
  resources: BillingProjectResourceItem[]
}

export const BILLING_PROJECT_RESOURCE_CATEGORY_ORDER: BillingProjectResourceCategory[] =
  [
    'addons',
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
  addons: 'Addons',
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

export const DEDICATED_DB_BILLING_METRIC_IDS = [
  'dedicatedDbSpecificationCost',
  'dedicatedDbStorage',
  'dedicatedDbBandwidth',
  'dedicatedDbHaReplica',
  // Cloud aggregations currently emit `dedicatedDbReplica.*` (not HaReplica).
  'dedicatedDbReplica',
  'dedicatedDbCrossRegionReplica',
  'dedicatedDbCrossRegion',
  'dedicatedDbPitr',
  'dedicatedDbExtensions',
] as const

export type DedicatedDbBillingMetricId =
  (typeof DEDICATED_DB_BILLING_METRIC_IDS)[number]

export type ParsedDedicatedDbBillingResourceId = {
  metricId: DedicatedDbBillingMetricId
  engine: string
  specSlug: string
}

/** Lookup keyed by `{engine}.{specSlug}` and by `specSlug` alone. */
export type DedicatedDbBillingSpecLookup = Record<
  string,
  { label: string; cpu?: string; memory?: string }
>

const DEDICATED_DB_PROJECT_RESOURCES: Record<
  DedicatedDbBillingMetricId,
  BillingProjectResourceMapping
> = {
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
  dedicatedDbReplica: {
    name: 'Replicas',
    format: 'number',
    planKey: 'dedicatedDbReplica',
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
  ...DEDICATED_DB_BILLING_METRIC_IDS,
  'executions',
  'GBHours',
  'screenshotsGenerated',
  'realtime',
  'realtimeMessages',
  'realtimeBandwidth',
]

function formatDedicatedDbEngineLabel(engine: string): string {
  const normalized = engine.trim().toLowerCase()
  if (normalized === 'postgres' || normalized === 'postgresql') {
    return 'PostgreSQL'
  }
  if (normalized === 'mysql') return 'MySQL'
  if (normalized === 'mariadb') return 'MariaDB'
  if (normalized === 'mongodb') return 'MongoDB'
  if (!normalized) return 'Database'
  return normalized.charAt(0).toUpperCase() + normalized.slice(1)
}

function formatDedicatedDbSpecSlugFallback(specSlug: string): string {
  const match = specSlug.match(/^s-(\d+)vcpu-(\d+)gb$/i)
  if (match) {
    return `${match[1]} vCPU · ${match[2]} GB`
  }
  return specSlug
}

function resolveDedicatedDbSpecLabel(
  engine: string,
  specSlug: string,
  specLookup?: DedicatedDbBillingSpecLookup | null,
): string {
  const compositeKey = `${engine}.${specSlug}`
  const fromLookup =
    specLookup?.[compositeKey] ?? specLookup?.[specSlug] ?? null

  if (fromLookup?.label?.trim()) {
    return fromLookup.label.trim()
  }

  const fromStaticSpec = getSpecOptionById(specSlug)
  if (fromStaticSpec?.label?.trim()) {
    return fromStaticSpec.label.trim()
  }

  const cpuMemory =
    fromLookup?.cpu && fromLookup?.memory
      ? `${fromLookup.cpu} · ${fromLookup.memory}`
      : fromStaticSpec &&
          fromStaticSpec.cpu !== '-' &&
          fromStaticSpec.memory !== '-'
        ? `${fromStaticSpec.cpu} · ${fromStaticSpec.memory}`
        : null

  return cpuMemory ?? formatDedicatedDbSpecSlugFallback(specSlug)
}

export function parseDedicatedDbBillingResourceId(
  resourceId: string,
): ParsedDedicatedDbBillingResourceId | null {
  for (const metricId of DEDICATED_DB_BILLING_METRIC_IDS) {
    const prefix = `${metricId}.`
    if (!resourceId.startsWith(prefix)) continue

    const rest = resourceId.slice(prefix.length)
    const dotIndex = rest.indexOf('.')
    if (dotIndex <= 0) return null

    const engine = rest.slice(0, dotIndex).trim()
    const specSlug = rest.slice(dotIndex + 1).trim()
    if (!engine || !specSlug) return null

    return { metricId, engine, specSlug }
  }

  return null
}

export function buildDedicatedDbBillingSpecLookup(
  specifications:
    | Models.DedicatedDatabaseSpecification[]
    | SpecOption[]
    | undefined,
): DedicatedDbBillingSpecLookup {
  const lookup: DedicatedDbBillingSpecLookup = {}

  const specOptions = Array.isArray(specifications)
    ? specifications.length > 0 &&
        typeof specifications[0] === 'object' &&
        specifications[0] !== null &&
        'slug' in specifications[0]
      ? mapDedicatedDatabaseSpecifications(
          specifications as Models.DedicatedDatabaseSpecification[],
        )
      : (specifications as SpecOption[])
    : []

  for (const spec of specOptions) {
    lookup[spec.id] = {
      label: spec.label,
      cpu: spec.cpu,
      memory: spec.memory,
    }
  }

  return lookup
}

export function buildDedicatedDbBillingSpecTitle(
  engine: string,
  specSlug: string,
  specLookup?: DedicatedDbBillingSpecLookup | null,
): string {
  const engineLabel = formatDedicatedDbEngineLabel(engine)
  const specLabel = resolveDedicatedDbSpecLabel(engine, specSlug, specLookup)
  return `${engineLabel} · ${specLabel}`
}

const DEDICATED_DB_BILLING_METRIC_SHORT_LABELS: Record<
  DedicatedDbBillingMetricId,
  string
> = {
  dedicatedDbSpecificationCost: 'Compute',
  dedicatedDbStorage: 'Storage',
  dedicatedDbBandwidth: 'Bandwidth',
  dedicatedDbHaReplica: 'HA replicas',
  dedicatedDbReplica: 'Replicas',
  dedicatedDbCrossRegionReplica: 'Cross-region replicas',
  dedicatedDbCrossRegion: 'Cross-region transfer',
  dedicatedDbPitr: 'Point-in-time recovery',
  dedicatedDbExtensions: 'Extensions',
}

export function getDedicatedDbBillingMetricShortLabel(
  metricId: DedicatedDbBillingMetricId,
  plan?: Models.BillingPlan | null,
): string {
  const fromPlan = getBillingResourceLabel(
    metricId,
    DEDICATED_DB_BILLING_METRIC_SHORT_LABELS[metricId],
    plan,
  )

  if (fromPlan === DEDICATED_DB_PROJECT_RESOURCES[metricId].name) {
    return DEDICATED_DB_BILLING_METRIC_SHORT_LABELS[metricId]
  }

  return fromPlan
}

function formatBillingBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1000
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.min(
    Math.floor(Math.log(bytes) / Math.log(k)),
    sizes.length - 1,
  )
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`
}

function formatBillingHoursFromMinutes(minutes: number): string {
  const hours = minutes / 60
  if (hours >= 100) {
    return `${Math.round(hours).toLocaleString()} hours`
  }
  return `${hours.toFixed(1).replace(/\.0$/, '')} hours`
}

/** Human-readable usage with an explicit unit for dedicated DB billing rows. */
export function formatDedicatedDbBillingUsageLabel(
  usage: number,
  metricId: DedicatedDbBillingMetricId,
  formatType: BillingProjectResourceFormat,
): string {
  if (formatType === 'bytes') {
    return formatBillingBytes(usage)
  }

  switch (metricId) {
    case 'dedicatedDbSpecificationCost':
      return formatBillingHoursFromMinutes(usage)
    case 'dedicatedDbPitr':
    case 'dedicatedDbExtensions':
      return usage === 1 ? '1 instance' : `${usage.toLocaleString()} instances`
    case 'dedicatedDbHaReplica':
    case 'dedicatedDbReplica':
    case 'dedicatedDbCrossRegionReplica':
      return usage === 1 ? '1 replica' : `${usage.toLocaleString()} replicas`
    case 'dedicatedDbCrossRegion':
      return usage === 1
        ? '1 transfer'
        : `${usage.toLocaleString()} transfers`
    default:
      return usage.toLocaleString()
  }
}

export function getDedicatedDbBillingUsageDescription(
  metricId: DedicatedDbBillingMetricId,
  formatType: BillingProjectResourceFormat,
): string | undefined {
  if (formatType === 'bytes') {
    if (metricId === 'dedicatedDbStorage') {
      return 'Additional storage used beyond the tier allowance.'
    }
    if (metricId === 'dedicatedDbBandwidth') {
      return 'Network egress for this database tier.'
    }
    return undefined
  }

  switch (metricId) {
    case 'dedicatedDbSpecificationCost':
      return 'Billable compute runtime during this billing period, in hours.'
    case 'dedicatedDbPitr':
      return 'Number of instances with point-in-time recovery enabled.'
    case 'dedicatedDbExtensions':
      return 'Number of billable database extensions.'
    case 'dedicatedDbHaReplica':
      return 'Number of high availability replicas provisioned.'
    case 'dedicatedDbReplica':
      return 'Number of replicas provisioned.'
    case 'dedicatedDbCrossRegionReplica':
      return 'Number of cross-region replicas provisioned.'
    case 'dedicatedDbCrossRegion':
      return 'Cross-region data transfer usage.'
    default:
      return undefined
  }
}

export type DedicatedDbBillingSpecGroupItem = {
  resourceId: string
  metricId: DedicatedDbBillingMetricId
  metricLabel: string
  usage: number
  limit: number | null
  cost: number
  formatType: BillingProjectResourceFormat
  showLimit?: boolean
}

export type DedicatedDbBillingSpecGroup = {
  key: string
  title: string
  totalCost: number
  items: DedicatedDbBillingSpecGroupItem[]
}

export function groupDedicatedDbBillingResources(
  resources: BillingProjectResourceItem[],
  plan?: Models.BillingPlan | null,
  specLookup?: DedicatedDbBillingSpecLookup | null,
): {
  specGroups: DedicatedDbBillingSpecGroup[]
  ungrouped: BillingProjectResourceItem[]
} {
  const groups = new Map<string, DedicatedDbBillingSpecGroup>()
  const ungrouped: BillingProjectResourceItem[] = []

  for (const resource of resources) {
    const parsed = parseDedicatedDbBillingResourceId(resource.resourceId)
    if (!parsed) {
      ungrouped.push(resource)
      continue
    }

    const key = `${parsed.engine}.${parsed.specSlug}`
    const existing = groups.get(key) ?? {
      key,
      title: buildDedicatedDbBillingSpecTitle(
        parsed.engine,
        parsed.specSlug,
        specLookup,
      ),
      totalCost: 0,
      items: [],
    }

    existing.items.push({
      resourceId: resource.resourceId,
      metricId: parsed.metricId,
      metricLabel: getDedicatedDbBillingMetricShortLabel(parsed.metricId, plan),
      usage: resource.usage,
      limit: resource.limit,
      cost: resource.cost,
      formatType: resource.formatType,
      showLimit: resource.showLimit,
    })
    existing.totalCost += resource.cost
    groups.set(key, existing)
  }

  const specGroups = [...groups.values()]
    .map((group) => ({
      ...group,
      items: group.items.sort(
        (a, b) =>
          DEDICATED_DB_BILLING_METRIC_IDS.indexOf(a.metricId) -
          DEDICATED_DB_BILLING_METRIC_IDS.indexOf(b.metricId),
      ),
    }))
    .sort((a, b) => a.key.localeCompare(b.key))

  return { specGroups, ungrouped }
}

export function resolveBillingProjectResourceMapping(
  resourceId: string,
  plan: Models.BillingPlan | null | undefined,
  specLookup?: DedicatedDbBillingSpecLookup | null,
): BillingProjectResourceMapping | null {
  const baseMap = getBillingProjectResourceIdMap(plan)
  const direct = baseMap[resourceId]
  if (direct) return direct

  const parsed = parseDedicatedDbBillingResourceId(resourceId)
  if (!parsed) return null

  const baseMapping = DEDICATED_DB_PROJECT_RESOURCES[parsed.metricId]
  if (!baseMapping) return null

  const baseName = getBillingResourceLabel(
    parsed.metricId,
    baseMapping.name,
    plan,
  )
  const engineLabel = formatDedicatedDbEngineLabel(parsed.engine)
  const specLabel = resolveDedicatedDbSpecLabel(
    parsed.engine,
    parsed.specSlug,
    specLookup,
  )

  return {
    ...baseMapping,
    name: `${baseName} (${engineLabel} · ${specLabel})`,
  }
}

function compareDedicatedDbBillingResourceIds(a: string, b: string): number {
  const parsedA = parseDedicatedDbBillingResourceId(a)
  const parsedB = parseDedicatedDbBillingResourceId(b)

  if (parsedA && parsedB) {
    const specCompare = `${parsedA.engine}.${parsedA.specSlug}`.localeCompare(
      `${parsedB.engine}.${parsedB.specSlug}`,
    )
    if (specCompare !== 0) return specCompare

    const metricA = DEDICATED_DB_BILLING_METRIC_IDS.indexOf(parsedA.metricId)
    const metricB = DEDICATED_DB_BILLING_METRIC_IDS.indexOf(parsedB.metricId)
    return metricA - metricB
  }

  if (parsedA) return 1
  if (parsedB) return -1
  return 0
}

function getBillingProjectResourceSortIndex(resourceId: string): number {
  const index = BILLING_PROJECT_RESOURCE_ID_ORDER.indexOf(resourceId)
  return index === -1 ? BILLING_PROJECT_RESOURCE_ID_ORDER.length : index
}

function compareBillingProjectResourceIds(a: string, b: string): number {
  const dedicatedCompare = compareDedicatedDbBillingResourceIds(a, b)
  if (dedicatedCompare !== 0) return dedicatedCompare
  return getBillingProjectResourceSortIndex(a) - getBillingProjectResourceSortIndex(b)
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
      .sort((a, b) =>
        compareBillingProjectResourceIds(a.resourceId, b.resourceId),
      ),
  }))
}

export type BillingProjectBreakdown = {
  projectId: string
  projectName: string
  categories: BillingProjectResourceCategoryGroup[]
  total: number
}

export type AggregationResourceLike = {
  resourceId?: string
  value?: number | string | null
  amount?: number | string | null
  name?: string
}

const BILLING_PLAN_RESOURCE_PROPERTY_MAP: Record<string, string> = {
  bandwidth: 'bandwidth',
  storage: 'storage',
  users: 'users',
  executions: 'executions',
  gbHours: 'GBHours',
  databaseReads: 'databasesReads',
  databaseWrites: 'databasesWrites',
  imageTransformations: 'imageTransformations',
  screenshotsGenerated: 'screenshotsGenerated',
  authPhone: 'authPhone',
  realtime: 'realtime',
  realtimeMessages: 'realtimeMessages',
  realtimeBandwidth: 'realtimeBandwidth',
}

function getBillingPlanRawResourceLimit(
  plan: Models.BillingPlan | null | undefined,
  planKey: string,
): number | null {
  if (!plan) return null

  const planProperty = BILLING_PLAN_RESOURCE_PROPERTY_MAP[planKey] || planKey
  const limitValue = (plan as unknown as Record<string, unknown>)[planProperty]
  if (limitValue === null || limitValue === undefined) return null

  const numValue = Number(limitValue)
  if (!Number.isFinite(numValue)) return null
  return numValue
}

/**
 * True when the plan sets this resource to -1 (disabled / not available).
 */
export function isBillingPlanResourceUnavailable(
  plan: Models.BillingPlan | null | undefined,
  planKey: string,
): boolean {
  const raw = getBillingPlanRawResourceLimit(plan, planKey)
  return raw !== null && raw < 0
}

/**
 * Plan resource limit in the same units as usage (bytes for bandwidth/storage).
 * Returns null when unlimited / unset / disabled (-1).
 */
export function getBillingPlanResourceLimit(
  plan: Models.BillingPlan | null | undefined,
  planKey: string,
): number | null {
  const numValue = getBillingPlanRawResourceLimit(plan, planKey)
  if (numValue === null) return null
  // -1 typically means disabled/unavailable on Cloud plans
  if (numValue < 0) return null

  if (planKey === 'bandwidth' || planKey === 'storage') {
    return numValue * 1_000_000_000
  }
  return numValue
}

function aggregationResourceValue(
  resources: AggregationResourceLike[],
  ...resourceIds: string[]
): number {
  for (const resourceId of resourceIds) {
    const match = resources.find((r) => r.resourceId === resourceId)
    if (!match) continue
    const n = Number(match.value)
    if (Number.isFinite(n)) return Math.max(0, n)
  }
  return 0
}

function aggregationResourceAmount(
  resources: AggregationResourceLike[],
  ...resourceIds: string[]
): number {
  for (const resourceId of resourceIds) {
    const match = resources.find((r) => r.resourceId === resourceId)
    if (!match) continue
    const n = Number(match.amount)
    if (Number.isFinite(n)) return Math.max(0, n)
  }
  return 0
}

/**
 * Org-scoped usage vs plan limits from billing aggregation `resources`.
 * Used when `usagePerProject` is false (e.g. Free): `breakdown` is empty but
 * team aggregation still carries full org resource totals.
 *
 * Always includes the same base resource list as paid plan project breakdowns.
 * Resources disabled on the plan (limit -1) are included with `requiresUpgrade`.
 */
export function buildOrganizationUsageCategoriesFromAggregation(
  resources: AggregationResourceLike[] | null | undefined,
  plan: Models.BillingPlan | null | undefined,
): BillingProjectResourceCategoryGroup[] {
  const aggregationResources = Array.isArray(resources) ? resources : []
  if (!plan && aggregationResources.length === 0) return []

  const resourceIdMap = getBillingProjectResourceIdMap(plan)
  const usageByResourceId: Record<string, { usage: number; cost: number }> = {
    bandwidth: {
      usage: aggregationResourceValue(aggregationResources, 'bandwidth'),
      cost: aggregationResourceAmount(aggregationResources, 'bandwidth'),
    },
    // Prefer metered `storage`; fall back to `totalStorage` when storage is unset
    storage: {
      usage: (() => {
        const storage = aggregationResourceValue(aggregationResources, 'storage')
        if (storage > 0) return storage
        return aggregationResourceValue(aggregationResources, 'totalStorage')
      })(),
      cost: aggregationResourceAmount(
        aggregationResources,
        'storage',
        'totalStorage',
      ),
    },
    mau: {
      usage: aggregationResourceValue(aggregationResources, 'mau', 'users'),
      cost: aggregationResourceAmount(aggregationResources, 'mau', 'users'),
    },
    executions: {
      usage: aggregationResourceValue(aggregationResources, 'executions'),
      cost: aggregationResourceAmount(aggregationResources, 'executions'),
    },
    databasesReads: {
      usage: aggregationResourceValue(aggregationResources, 'databasesReads'),
      cost: aggregationResourceAmount(aggregationResources, 'databasesReads'),
    },
    databasesWrites: {
      usage: aggregationResourceValue(aggregationResources, 'databasesWrites'),
      cost: aggregationResourceAmount(aggregationResources, 'databasesWrites'),
    },
    imageTransformations: {
      usage: aggregationResourceValue(
        aggregationResources,
        'imageTransformations',
      ),
      cost: aggregationResourceAmount(
        aggregationResources,
        'imageTransformations',
      ),
    },
    screenshotsGenerated: {
      usage: aggregationResourceValue(
        aggregationResources,
        'screenshotsGenerated',
      ),
      cost: aggregationResourceAmount(
        aggregationResources,
        'screenshotsGenerated',
      ),
    },
    authPhone: {
      usage: aggregationResourceValue(aggregationResources, 'authPhone'),
      cost: aggregationResourceAmount(aggregationResources, 'authPhone'),
    },
    // Aggregation already stores GBHours; do not recompute from MBSeconds
    GBHours: {
      usage: aggregationResourceValue(aggregationResources, 'GBHours'),
      cost: aggregationResourceAmount(aggregationResources, 'GBHours'),
    },
    realtime: {
      usage: aggregationResourceValue(aggregationResources, 'realtime'),
      cost: aggregationResourceAmount(aggregationResources, 'realtime'),
    },
    realtimeMessages: {
      usage: aggregationResourceValue(aggregationResources, 'realtimeMessages'),
      cost: aggregationResourceAmount(aggregationResources, 'realtimeMessages'),
    },
    realtimeBandwidth: {
      usage: aggregationResourceValue(aggregationResources, 'realtimeBandwidth'),
      cost: aggregationResourceAmount(aggregationResources, 'realtimeBandwidth'),
    },
  }

  const items: BillingProjectResourceItem[] = []
  for (const resourceId of BILLING_PROJECT_RESOURCE_ID_ORDER) {
    const mapping = resourceIdMap[resourceId]
    if (!mapping || mapping.showOnlyWhenUsed) continue

    const values = usageByResourceId[resourceId] ?? { usage: 0, cost: 0 }
    const requiresUpgrade = isBillingPlanResourceUnavailable(
      plan,
      mapping.planKey,
    )
    const limit = requiresUpgrade
      ? null
      : getBillingPlanResourceLimit(plan, mapping.planKey)

    items.push({
      resourceId,
      name: mapping.name,
      usage: values.usage,
      limit,
      cost: values.cost,
      formatType: mapping.format,
      showLimit: requiresUpgrade ? false : mapping.showLimit !== false,
      requiresUpgrade,
      category: mapping.category,
    })
  }

  return groupBillingProjectResources(items)
}


