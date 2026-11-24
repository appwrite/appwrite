import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { ADDON_KEY_PREMIUM_GEO_DB } from '@/lib/billing/addons'
import {
  enableProjectAddon,
  type EnableProjectAddonParams,
  type EnableProjectAddonResult,
} from '@/lib/billing/enable-project-addon'

export type EnablePremiumGeoDbAddonResult = EnableProjectAddonResult

/**
 * Enables the project-scoped Premium Geo DB addon (payment + confirm when required).
 */
export async function enablePremiumGeoDbAddon(
  params: EnableProjectAddonParams,
): Promise<EnablePremiumGeoDbAddonResult> {
  return enableProjectAddon({
    ...params,
    addonKey: ADDON_KEY_PREMIUM_GEO_DB,
    create: async (projectId) =>
      (await sdk.forConsole.projects.createPremiumGeoDBAddon({
        projectId,
      })) as Models.Addon | Models.PaymentAuthentication,
  })
}
