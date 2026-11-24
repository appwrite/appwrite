import { sdk } from '@/lib/appwrite/sdk'
import { ADDON_KEY_SSO } from '@/lib/billing/addons'
import {
  enableProjectAddon,
  type EnableProjectAddonParams,
  type EnableProjectAddonResult,
} from '@/lib/billing/enable-project-addon'

/**
 * Enables the project's Single sign-on add-on, for single sign-on in its teams
 * (payment + confirm when required).
 */
export async function enableSSOAddon(
  params: EnableProjectAddonParams,
): Promise<EnableProjectAddonResult> {
  return enableProjectAddon({
    ...params,
    addonKey: ADDON_KEY_SSO,
    // Hand-written until the Console SDK carries `projects.createSSOAddon`
    create: (projectId) => sdk.forConsole.teamSSO.createSSOAddon({ projectId }),
  })
}
