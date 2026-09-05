import type { Models } from '@appwrite.io/console'
import type { DatabaseRouteKind } from '@/lib/database-routes'

export type DowngradeResourceType =
  | 'databases'
  | 'buckets'
  | 'functions'
  | 'sites'
  | 'teams'
  | 'topics'
  | 'platforms'
  | 'webhooks'
  | 'wafRules'

export type DowngradeResourceItem = {
  $id: string
  name: string
  /** Product route kind; only set for `databases` resource items. */
  dbKind?: DatabaseRouteKind
}

export type DowngradeResourceGroup = {
  items: DowngradeResourceItem[]
  total: number
}

export type ProjectDowngradeResources = Record<
  DowngradeResourceType,
  DowngradeResourceGroup
>

export const DOWNGRADE_RESOURCE_TYPES: {
  id: DowngradeResourceType
  label: string
  planKey: DowngradeResourceType
}[] = [
  { id: 'databases', label: 'Databases', planKey: 'databases' },
  { id: 'buckets', label: 'Buckets', planKey: 'buckets' },
  { id: 'functions', label: 'Functions', planKey: 'functions' },
  { id: 'sites', label: 'Sites', planKey: 'sites' },
  { id: 'teams', label: 'Teams', planKey: 'teams' },
  { id: 'topics', label: 'Topics', planKey: 'topics' },
  { id: 'platforms', label: 'Platforms', planKey: 'platforms' },
  { id: 'webhooks', label: 'Webhooks', planKey: 'webhooks' },
  { id: 'wafRules', label: 'Firewall rules', planKey: 'wafRules' },
]

export type DowngradeOrgResourceType = 'members' | 'domains'

export type DowngradePlanLimits = Record<
  DowngradeResourceType | 'projects' | DowngradeOrgResourceType,
  number | null
>

/**
 * Per-project resource limits. Narrower than {@link DowngradePlanLimits} so
 * server-supplied, per-project limits can be used in the same helpers.
 */
export type DowngradeResourceLimits = Record<
  DowngradeResourceType,
  number | null
>

function readPlanLimit(
  targetPlan: Record<string, unknown> | null | undefined,
  key: string,
  allowZero = false,
): number | null {
  const value = targetPlan?.[key]
  if (value === null || value === undefined) return null
  const num = Number(value)
  if (Number.isNaN(num) || num < 0) return null
  if (!allowZero && num <= 0) return null
  return num
}

function readAddonLimit(
  targetPlan: Record<string, unknown> | null | undefined,
  addonKey: string,
  allowZero = false,
): number | null {
  const addons = targetPlan?.addons as
    | Record<
        string,
        {
          limit?: number
          planIncluded?: number
          supported?: boolean
        }
      >
    | undefined
  const addon = addons?.[addonKey]
  if (!addon) return null
  const value = addon.limit ?? addon.planIncluded
  if (value === null || value === undefined) return null
  const num = Number(value)
  if (Number.isNaN(num) || num < 0) return null
  if (!allowZero && num <= 0) return null
  return num
}

export function getMemberLimit(
  targetPlan: Record<string, unknown> | null | undefined,
): number | null {
  if (!targetPlan) return null

  const addons = targetPlan.addons as
    | Record<
        string,
        {
          limit?: number
          planIncluded?: number
          supported?: boolean
        }
      >
    | undefined

  const limitCandidates = [
    addons?.seats?.limit,
    addons?.seats?.planIncluded,
    targetPlan.members,
  ]

  for (const value of limitCandidates) {
    if (value === null || value === undefined) continue
    const num = Number(value)
    if (!Number.isNaN(num) && num > 0) {
      return num
    }
  }

  return null
}

export function getDowngradePlanLimits(
  targetPlan: Record<string, unknown> | null | undefined,
): DowngradePlanLimits {
  const planName =
    typeof targetPlan?.name === 'string' ? targetPlan.name.toLowerCase() : ''
  const isFreePlan =
    planName.includes('free') ||
    planName === 'starter' ||
    (targetPlan &&
      Number(targetPlan.price ?? 0) === 0 &&
      Number(targetPlan.order ?? 0) <= 0)

  return {
    projects:
      readPlanLimit(targetPlan, 'projects') ??
      readAddonLimit(targetPlan, 'projects'),
    members: getMemberLimit(targetPlan) ?? (isFreePlan ? 1 : null),
    domains:
      readPlanLimit(targetPlan, 'domains', true) ??
      readAddonLimit(targetPlan, 'domains', true) ??
      (isFreePlan ? 0 : null),
    databases: readPlanLimit(targetPlan, 'databases'),
    buckets: readPlanLimit(targetPlan, 'buckets'),
    functions: readPlanLimit(targetPlan, 'functions'),
    sites: readPlanLimit(targetPlan, 'sites'),
    teams: readPlanLimit(targetPlan, 'teams'),
    topics: readPlanLimit(targetPlan, 'topics'),
    platforms: readPlanLimit(targetPlan, 'platforms'),
    webhooks: readPlanLimit(targetPlan, 'webhooks'),
    wafRules: readPlanLimit(targetPlan, 'wafRules'),
  }
}

export function getResourceViolationCount(
  total: number,
  limit: number | null,
): number {
  if (limit === null) return 0
  return Math.max(0, total - limit)
}

export function projectHasResourceViolations(
  resources: ProjectDowngradeResources | undefined,
  limits: DowngradeResourceLimits,
): boolean {
  if (!resources) return false

  return DOWNGRADE_RESOURCE_TYPES.some(({ id }) => {
    const limit = limits[id]
    if (limit === null) return false
    return resources[id].total > limit
  })
}

export function countResourcesToDeleteForProject(
  resources: ProjectDowngradeResources,
  limits: DowngradeResourceLimits,
): Partial<Record<DowngradeResourceType, number>> {
  const counts: Partial<Record<DowngradeResourceType, number>> = {}

  for (const { id } of DOWNGRADE_RESOURCE_TYPES) {
    const deleteCount = getResourceViolationCount(
      resources[id].total,
      limits[id],
    )
    if (deleteCount > 0) {
      counts[id] = deleteCount
    }
  }

  return counts
}

export type DowngradeResourceImpact = Partial<
  Record<DowngradeResourceType, number>
>

export function mergeResourceImpacts(
  impacts: DowngradeResourceImpact[],
): DowngradeResourceImpact {
  const merged: DowngradeResourceImpact = {}

  for (const impact of impacts) {
    for (const { id } of DOWNGRADE_RESOURCE_TYPES) {
      const count = impact[id]
      if (!count) continue
      merged[id] = (merged[id] ?? 0) + count
    }
  }

  return merged
}

export function getTotalResourceDeletions(
  impact: DowngradeResourceImpact,
): number {
  return DOWNGRADE_RESOURCE_TYPES.reduce(
    (total, { id }) => total + (impact[id] ?? 0),
    0,
  )
}

export type DowngradeProjects = Models.Project[]
