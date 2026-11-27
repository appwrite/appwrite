import type { Models } from '@appwrite.io/console'
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
  // Like the other add-ons, it resolves with the add-on, or with a payment to
  // authenticate and confirm first.
  return target.scope === 'project'
    ? enableAddon({
        ...params,
        addonKey: ADDON_KEY_SSO,
        create: async () =>
          (await sdk.forConsole.projects.createSSOAddon({
            projectId: target.projectId,
          })) as Models.Addon | Models.PaymentAuthentication,
      })
    : enableAddon({
        ...params,
        addonKey: ADDON_KEY_SSO_ORG,
        create: async () =>
          (await sdk.forConsole.organizations.createSSOAddon({
            organizationId: target.organizationId,
          })) as Models.Addon | Models.PaymentAuthentication,
      })
}
