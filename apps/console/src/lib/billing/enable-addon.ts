import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  isPaymentAuthentication,
  resolveStripeProviderMethodId,
} from '@/lib/billing/addons'
import { refetchOrganizationBillingQueries } from '@/lib/billing/refetch-organization-billing-queries'
import {
  organizationAddonPriceQueryOptions,
  organizationAddonsQueryOptions,
  projectAddonPriceQueryOptions,
  projectAddonsQueryOptions,
} from '@/lib/react-query/hooks/addons'
import { confirmPayment } from '@/lib/utils/stripe'

/** What an add-on belongs to: a project, or an organization. */
export type AddonTarget =
  | { scope: 'project'; projectId: string }
  | { scope: 'organization'; organizationId: string }

export type EnableAddonResult = 'enabled' | 'already_active'

/** What enabling needs besides the add-on: the organization that pays, and how. */
export type EnableAddonParams = {
  organizationId: string
  paymentMethodId: string | null | undefined
  missingPaymentMethodMessage: string
  queryClient?: QueryClient
}

export type EnableProjectAddonParams = EnableAddonParams & {
  projectId: string
}

/** Reload what an add-on change shows: the add-ons, the price and billing. */
export async function refreshAddonQueries(
  queryClient: QueryClient,
  target: AddonTarget,
  addonKey: string,
  organizationId?: string,
) {
  await Promise.all([
    ...(target.scope === 'project'
      ? [
          queryClient.refetchQueries({
            queryKey: projectAddonsQueryOptions(target.projectId).queryKey,
          }),
          queryClient.refetchQueries({
            queryKey: projectAddonPriceQueryOptions(target.projectId, addonKey)
              .queryKey,
          }),
          queryClient.refetchQueries({
            queryKey: ['project', target.projectId],
          }),
        ]
      : [
          queryClient.refetchQueries({
            queryKey: organizationAddonsQueryOptions(target.organizationId)
              .queryKey,
          }),
          queryClient.refetchQueries({
            queryKey: organizationAddonPriceQueryOptions(
              target.organizationId,
              addonKey,
            ).queryKey,
          }),
        ]),
    ...(organizationId
      ? [refetchOrganizationBillingQueries(queryClient, organizationId)]
      : []),
  ])
}

/** Confirm a paid add-on with the API, at its scope. */
export function confirmAddonPayment(target: AddonTarget, addonId: string) {
  return target.scope === 'project'
    ? sdk.forConsole.projects.confirmAddonPayment({
        projectId: target.projectId,
        addonId,
      })
    : sdk.forConsole.organizations.confirmAddonPayment({
        organizationId: target.organizationId,
        addonId,
      })
}

/** After Stripe: an add-on the API already settled is no failure. */
async function confirmSettledAddonPayment(
  target: AddonTarget,
  addonId: string,
) {
  try {
    await confirmAddonPayment(target, addonId)
  } catch (confirmError) {
    const candidate = confirmError as { type?: string; code?: number }
    if (
      candidate?.type !== 'billing_invoice_not_found' &&
      candidate?.type !== 'addon_not_found' &&
      candidate?.code !== 404
    ) {
      throw confirmError
    }
  }
}

/**
 * Enables an addon of a project or an organization (payment + confirm when
 * required). `create` asks the API for the addon `addonKey`.
 */
export async function enableAddon(
  params: EnableAddonParams & {
    target: AddonTarget
    addonKey: string
    create: () => Promise<Models.Addon | Models.PaymentAuthentication>
  },
): Promise<EnableAddonResult> {
  const {
    organizationId,
    paymentMethodId,
    missingPaymentMethodMessage,
    queryClient,
    target,
    addonKey,
    create,
  } = params

  try {
    const result = await create()

    if (isPaymentAuthentication(result)) {
      if (!paymentMethodId) {
        throw new Error(missingPaymentMethodMessage)
      }
      const providerMethodId = await resolveStripeProviderMethodId({
        organizationId,
        paymentMethodId,
      })
      await confirmPayment({
        clientSecret: result.clientSecret,
        paymentMethod: providerMethodId,
      })
      await confirmSettledAddonPayment(target, result.addonId)
    }

    if (queryClient) {
      await refreshAddonQueries(queryClient, target, addonKey, organizationId)
    }

    return 'enabled'
  } catch (error) {
    const candidate = error as { code?: number }
    if (candidate?.code === 409) {
      if (queryClient) {
        await refreshAddonQueries(queryClient, target, addonKey, organizationId)
      }
      return 'already_active'
    }
    throw error
  }
}
