import { sdk } from '@/lib/appwrite/sdk'
import { ADDON_KEY_SSO, ADDON_KEY_SSO_ORG } from '@/lib/billing/addons'
import {
  enableAddon,
  type AddonTarget,
  type EnableAddonParams,
  type EnableAddonResult,
} from '@/lib/billing/enable-addon'

/**
 * Enables a Single sign-on add-on (payment + confirm when required): the
 * project's (`sso`), for single sign-on in its teams, or the organization's
 * (`ssoOrg`), for single sign-on into the Console.
 */
export async function enableSSOAddon(
  params: EnableAddonParams & { target: AddonTarget },
): Promise<EnableAddonResult> {
  const { target } = params
  // Hand-written until the Console SDK carries `projects.createSSOAddon` and
  // `organizations.createSSOAddon`
  return target.scope === 'project'
    ? enableAddon({
        ...params,
        addonKey: ADDON_KEY_SSO,
        create: () =>
          sdk.forConsole.teamSSO.createProjectSSOAddon({
            projectId: target.projectId,
          }),
      })
    : enableAddon({
        ...params,
        addonKey: ADDON_KEY_SSO_ORG,
        create: () =>
          sdk.forConsole.teamSSO.createOrganizationSSOAddon({
            organizationId: target.organizationId,
          }),
      })
}
