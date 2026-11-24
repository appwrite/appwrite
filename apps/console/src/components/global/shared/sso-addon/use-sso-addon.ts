import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  ADDON_KEY_SSO,
  ADDON_KEY_SSO_ORG,
  findActiveOrPendingAddon,
  planSupportsAddon,
  type SupportedAddonKey,
} from '@/lib/billing/addons'
import type { AddonTarget } from '@/lib/billing/enable-addon'
import { useI18n } from '@/lib/i18n'
import {
  organizationAddonsQueryOptions,
  projectAddonsQueryOptions,
  useBillingPlans,
  useOrganizationAddonPrice,
  useOrganizationById,
  useOrganizationPlan,
  useProject,
  useProjectAddonPrice,
} from '@/lib/react-query/hooks'
import { ssoAddonStatus } from '@/lib/sso-addon'

/**
 * Whose Single sign-on add-on: a project's (`sso`), for single sign-on in its
 * teams, or an organization's (`ssoOrg`), for single sign-on into the Console.
 * Inside an environment a project has the add-on of the project it belongs
 * to, read under the project's ID.
 */
export type SSOAddonTarget =
  | { scope: 'project'; projectId: string; inEnvironment?: boolean }
  | { scope: 'organization'; organizationId: string }

export function ssoAddonKey(target: SSOAddonTarget): SupportedAddonKey {
  return target.scope === 'project' ? ADDON_KEY_SSO : ADDON_KEY_SSO_ORG
}

export function toAddonTarget(target: SSOAddonTarget): AddonTarget {
  return target.scope === 'project'
    ? { scope: 'project', projectId: target.projectId }
    : { scope: 'organization', organizationId: target.organizationId }
}

/** The plans that sell `addon`, cheapest first, as one list in the language. */
export function usePlansSellingAddon(addon: SupportedAddonKey): string | null {
  const { language } = useI18n()
  const { plans } = useBillingPlans()
  return useMemo(() => {
    const names = Object.values(plans)
      .filter((plan) => planSupportsAddon(plan, addon))
      .sort((a, b) => a.order - b.order)
      .map((plan) => plan.name)
      .filter((name, index, all) => !!name && all.indexOf(name) === index)
    if (names.length === 0) return null
    return new Intl.ListFormat(language, { type: 'conjunction' }).format(names)
  }, [addon, language, plans])
}

/**
 * The Single sign-on add-on of `target`: the organization that pays for it,
 * its plan, the add-on list and the add-on's state and price. `enabled: false`
 * reads nothing; `withPrice` asks for the price.
 */
export function useSSOAddon(
  target: SSOAddonTarget,
  { enabled = true, withPrice = true } = {},
) {
  const key = ssoAddonKey(target)
  const projectId = target.scope === 'project' ? target.projectId : null
  const { project } = useProject(projectId ?? undefined)
  const orgId =
    target.scope === 'organization' ? target.organizationId : project?.teamId
  const { organization } = useOrganizationById(orgId)
  const planQuery = useOrganizationPlan(enabled ? orgId : null)
  const projectAddons = useQuery(
    projectAddonsQueryOptions(enabled ? projectId : null),
  )
  const organizationAddons = useQuery(
    organizationAddonsQueryOptions(
      enabled && target.scope === 'organization' ? orgId : null,
    ),
  )
  const addonsQuery =
    target.scope === 'project' ? projectAddons : organizationAddons
  const projectPrice = useProjectAddonPrice(
    enabled && withPrice ? projectId : null,
    ADDON_KEY_SSO,
  )
  const organizationPrice = useOrganizationAddonPrice(
    enabled && withPrice && target.scope === 'organization' ? orgId : null,
    ADDON_KEY_SSO_ORG,
  )

  const addons = addonsQuery.data?.addons
  const addon = useMemo(
    () => findActiveOrPendingAddon(addons, key),
    [addons, key],
  )

  return {
    key,
    orgId,
    organization,
    /** Known only once the project is read, for a project's add-on */
    targetReady: target.scope === 'organization' || !!project,
    planQuery,
    plan: planQuery.plan,
    addonsQuery,
    addon,
    status: ssoAddonStatus(planQuery.plan, addons, key),
    addonPrice:
      (target.scope === 'project'
        ? projectPrice.addonPrice
        : organizationPrice.addonPrice) ?? null,
  }
}
