import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

export const ADDON_KEY_PREMIUM_GEO_DB = 'premiumGeoDB'
export const ADDON_KEY_BAA = 'baa'

export type SupportedAddonKey = keyof Models.BillingPlanSupportedAddons

export function isPaymentAuthentication(
  result: unknown,
): result is Models.PaymentAuthentication {
  return (
    !!result &&
    typeof result === 'object' &&
    'clientSecret' in result &&
    typeof (result as Models.PaymentAuthentication).clientSecret === 'string'
  )
}

export function findActiveOrPendingAddon(
  addons: Models.Addon[] | undefined,
  key: string,
): Models.Addon | undefined {
  return addons?.find(
    (addon) =>
      addon.key === key &&
      (addon.status === 'active' || addon.status === 'pending'),
  )
}

export function isAddonScheduledForRemoval(addon: Models.Addon | undefined): boolean {
  return !!addon && addon.status === 'active' && addon.nextValue === 0
}

export function hasUpgradeablePlanWithAddon(
  currentPlan: Models.BillingPlan | null | undefined,
  plans: Record<string, Models.BillingPlan>,
  addonKey: SupportedAddonKey,
): boolean {
  if (!currentPlan) return false
  for (const plan of Object.values(plans)) {
    if (plan.order > currentPlan.order && plan.supportedAddons?.[addonKey]) {
      return true
    }
  }
  return false
}

export function getAddonConfirmSearchParams(search: string): {
  type: string | null
  addonId: string | null
} {
  const params = new URLSearchParams(search)
  return {
    type: params.get('type'),
    addonId: params.get('addonId'),
  }
}

/**
 * Resolve the Stripe payment method id (`pm_…`) for Stripe.js.
 * Organization.paymentMethodId is an Appwrite document id and must not be
 * passed to confirmCardPayment.
 */
export async function resolveStripeProviderMethodId(params: {
  organizationId: string
  paymentMethodId: string | null | undefined
}): Promise<string | undefined> {
  const { organizationId, paymentMethodId } = params
  if (!organizationId || !paymentMethodId) return undefined

  const method = await sdk.forConsole.organizations.getPaymentMethod({
    organizationId,
    paymentMethodId,
  })
  return method.providerMethodId || undefined
}
