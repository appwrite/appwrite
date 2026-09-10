import { queryOptions } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import {
  findActiveOrPendingAddon,
  isAddonScheduledForRemoval,
  type SupportedAddonKey,
} from '@/lib/billing/addons'
import { BILLING_ADDON_NAME_FALLBACK } from '@/lib/billing/billing-addon-charges'
import { fetchAllDowngradeProjects } from '@/lib/billing/fetch-downgrade-org-resources'
import {
  fetchOrganizationAddons,
  fetchProjectAddons,
} from '@/lib/react-query/hooks/addons'
import { formatProjectNameForDisplay } from '@/lib/react-query/hooks/projects'

export type DowngradeAddonScope = 'organization' | 'project'

export type DowngradeAddonRemoval = {
  key: string
  label: string
  scope: DowngradeAddonScope
  /** Organization id at organization scope, project id at project scope. */
  resourceId: string
  addonId: string
  projectName?: string
}

export type DowngradeAddonProjectSnapshot = {
  projectId: string
  projectName: string
  addons: Models.Addon[]
}

export type DowngradeAddonSnapshot = {
  organizationId: string
  organizationAddons: Models.Addon[]
  projects: DowngradeAddonProjectSnapshot[]
}

/**
 * The addon keys a plan declares support for are exactly the ones this flow can
 * disable. Anything else (`backup_recovery`) has no disable path here.
 */
const DOWNGRADE_ADDON_KEYS: SupportedAddonKey[] = [
  'baa',
  'premiumGeoDB',
  'premiumGeoDBOrg',
]

export function isDisableableDowngradeAddon(key: string): boolean {
  return (DOWNGRADE_ADDON_KEYS as string[]).includes(key)
}

function getAddonLabel(key: string): string {
  return BILLING_ADDON_NAME_FALLBACK[`addon_${key}`] ?? key
}

function readSupportedAddons(
  targetPlan: Record<string, unknown> | null | undefined,
): Partial<Record<SupportedAddonKey, boolean>> | null {
  const supported = targetPlan?.supportedAddons
  if (!supported || typeof supported !== 'object') return null
  return supported as Partial<Record<SupportedAddonKey, boolean>>
}

/**
 * Active addons the target plan does not support, at both scopes.
 * `premiumGeoDB` is project-scoped and never appears in the organization list
 * nor in the estimation's `unsupportedAddons`, so projects are diffed too.
 */
/**
 * An addon this flow can actually turn off now. A pending one is still
 * settling its payment and the API rejects the delete with a 400, and one
 * already scheduled for removal needs nothing further.
 */
function isRemovableNow(addon: Models.Addon): boolean {
  return addon.status === 'active' && !isAddonScheduledForRemoval(addon)
}

export function getUnsupportedAddonRemovals(
  snapshot: DowngradeAddonSnapshot | null | undefined,
  targetPlan: Record<string, unknown> | null | undefined,
): DowngradeAddonRemoval[] {
  const supported = readSupportedAddons(targetPlan)
  if (!snapshot || !supported) return []

  const removals: DowngradeAddonRemoval[] = []

  for (const key of DOWNGRADE_ADDON_KEYS) {
    if (supported[key]) continue
    const label = getAddonLabel(key)

    // `nextValue === 0` already schedules removal at cycle close, so there is
    // nothing left for this flow to do.
    const organizationAddon = findActiveOrPendingAddon(
      snapshot.organizationAddons,
      key,
    )
    if (organizationAddon && isRemovableNow(organizationAddon)) {
      removals.push({
        key,
        label,
        scope: 'organization',
        resourceId: snapshot.organizationId,
        addonId: organizationAddon.$id,
      })
    }

    for (const project of snapshot.projects) {
      const addon = findActiveOrPendingAddon(project.addons, key)
      if (!addon || !isRemovableNow(addon)) continue
      removals.push({
        key,
        label,
        scope: 'project',
        resourceId: project.projectId,
        addonId: addon.$id,
        projectName: project.projectName,
      })
    }
  }

  return removals
}

export async function fetchDowngradeAddons(
  organizationId: string,
): Promise<DowngradeAddonSnapshot> {
  const [organizationList, projects] = await Promise.all([
    fetchOrganizationAddons(organizationId),
    fetchAllDowngradeProjects(organizationId),
  ])

  const projectSnapshots = await Promise.all(
    projects.map(async (project) => ({
      projectId: project.$id,
      projectName: formatProjectNameForDisplay(project.name),
      addons: (await fetchProjectAddons(project.$id)).addons ?? [],
    })),
  )

  return {
    organizationId,
    organizationAddons: organizationList.addons ?? [],
    projects: projectSnapshots,
  }
}

export function downgradeAddonsQueryOptions(
  organizationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['downgrade-addons', organizationId],
    queryFn: () => fetchDowngradeAddons(organizationId!),
    enabled: !!organizationId,
    staleTime: 30_000,
  })
}

/**
 * Estimation-reported addon keys that are genuinely still active. The server's
 * check reads `currentValue` and ignores `nextValue`, so an addon this flow
 * just disabled is still reported until the cycle closes.
 */
export async function getUnresolvedUnsupportedAddons(
  organizationId: string,
  reportedKeys: string[],
): Promise<string[]> {
  if (reportedKeys.length === 0) return []

  const { addons } = await fetchOrganizationAddons(organizationId)

  return reportedKeys.filter((key) => {
    if (!isDisableableDowngradeAddon(key)) return true
    const addon = findActiveOrPendingAddon(addons, key)
    return !!addon && !isAddonScheduledForRemoval(addon)
  })
}
