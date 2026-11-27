import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { ADDON_KEY_PREMIUM_GEO_DB } from '@/lib/billing/addons'
import {
  enableAddon,
  type EnableAddonResult,
  type EnableProjectAddonParams,
} from '@/lib/billing/enable-addon'

export type EnablePremiumGeoDbAddonResult = EnableAddonResult

/**
 * Enables the project-scoped Premium Geo DB addon (payment + confirm when required).
 */
export async function enablePremiumGeoDbAddon(
  params: EnableProjectAddonParams,
): Promise<EnablePremiumGeoDbAddonResult> {
  const { projectId, ...rest } = params
  return enableAddon({
    ...rest,
    target: { scope: 'project', projectId },
    addonKey: ADDON_KEY_PREMIUM_GEO_DB,
    create: async () =>
      (await sdk.forConsole.projects.createPremiumGeoDBAddon({
        projectId,
      })) as Models.Addon | Models.PaymentAuthentication,
  })
}
