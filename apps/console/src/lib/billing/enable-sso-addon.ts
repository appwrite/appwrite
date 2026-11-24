import { sdk } from '@/lib/appwrite/sdk'
import { ADDON_KEY_SSO } from '@/lib/billing/addons'
import {
  enableAddon,
  type EnableAddonResult,
  type EnableProjectAddonParams,
} from '@/lib/billing/enable-addon'

/**
 * Enables the project's Single sign-on add-on, for single sign-on in its teams
 * (payment + confirm when required).
 */
export async function enableSSOAddon(
  params: EnableProjectAddonParams,
): Promise<EnableAddonResult> {
  const { projectId, ...rest } = params
  return enableAddon({
    ...rest,
    target: { scope: 'project', projectId },
    addonKey: ADDON_KEY_SSO,
    // Hand-written until the Console SDK carries `projects.createSSOAddon`
    create: () => sdk.forConsole.teamSSO.createSSOAddon({ projectId }),
  })
}
