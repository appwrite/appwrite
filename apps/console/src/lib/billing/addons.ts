import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { confirmPayment } from '@/lib/utils/stripe'

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

function paymentAuthenticationFromRecord(
  value: unknown,
  fallbackOrganizationId?: string,
): Models.PaymentAuthentication | null {
  if (!value || typeof value !== 'object') return null
  const record = value as Record<string, unknown>
  const clientSecret = record.clientSecret
  if (typeof clientSecret !== 'string' || !clientSecret.trim()) return null
  const organizationId =
    (typeof record.organizationId === 'string' && record.organizationId) ||
    (typeof record.$id === 'string' && record.$id) ||
    fallbackOrganizationId ||
    ''
  if (!organizationId) return null
  return {
    message: typeof record.message === 'string' ? record.message : '',
    clientSecret,
    organizationId,
    invoiceId: typeof record.invoiceId === 'string' ? record.invoiceId : '',
    addonId: typeof record.addonId === 'string' ? record.addonId : '',
    projectId: typeof record.projectId === 'string' ? record.projectId : '',
  }
}

/**
 * PaymentAuthentication may arrive as a 2xx body or as a thrown API error
 * (`message` + `clientSecret` on the object, or JSON in `response`).
 */
export function getPaymentAuthentication(
  value: unknown,
  fallbackOrganizationId?: string,
): Models.PaymentAuthentication | null {
  const direct = paymentAuthenticationFromRecord(value, fallbackOrganizationId)
  if (direct) return direct
  if (!value || typeof value !== 'object' || !('response' in value)) {
    return null
  }
  const response = (value as { response?: unknown }).response
  if (typeof response === 'string' && response.trim()) {
    try {
      return paymentAuthenticationFromRecord(
        JSON.parse(response),
        fallbackOrganizationId,
      )
    } catch {
      return null
    }
  }
  return paymentAuthenticationFromRecord(response, fallbackOrganizationId)
}

async function resolveStripePaymentMethodId(params: {
  organizationId?: string
  paymentMethodId?: string
}): Promise<string | undefined> {
  const { organizationId, paymentMethodId } = params
  if (!paymentMethodId) return undefined

  if (organizationId) {
    try {
      const fromOrg = await resolveStripeProviderMethodId({
        organizationId,
        paymentMethodId,
      })
      if (fromOrg) return fromOrg
    } catch {
      // Payment methods for partner billing are often account-scoped.
    }
  }

  try {
    const method = await sdk.forConsole.account.getPaymentMethod({
      paymentMethodId,
    })
    return method.providerMethodId || undefined
  } catch {
    return undefined
  }
}

/**
 * Complete Stripe SCA for an organization create/upgrade, then activate billing.
 */
export async function completeOrganizationPaymentAuthentication(params: {
  auth: Models.PaymentAuthentication
  paymentMethodId?: string
  organizationIdForPaymentMethod?: string
}): Promise<Models.Organization> {
  const organizationId = params.auth.organizationId
  if (!organizationId) {
    throw new Error(
      'Payment authentication is required but the server did not return an organization.',
    )
  }

  await confirmPayment({
    clientSecret: params.auth.clientSecret,
    paymentMethod: await resolveStripePaymentMethodId({
      organizationId: params.organizationIdForPaymentMethod,
      paymentMethodId: params.paymentMethodId,
    }),
  })

  return await sdk.forConsole.organizations.validatePayment({
    organizationId,
    invites: [],
  })
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

export function isAddonScheduledForRemoval(
  addon: Models.Addon | undefined,
): boolean {
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
