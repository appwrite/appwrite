import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  ADDON_KEY_PREMIUM_GEO_DB,
  isPaymentAuthentication,
  resolveStripeProviderMethodId,
} from '@/lib/billing/addons'
import { refetchOrganizationBillingQueries } from '@/lib/billing/refetch-organization-billing-queries'
import {
  projectAddonPriceQueryOptions,
  projectAddonsQueryOptions,
} from '@/lib/react-query/hooks/addons'
import { confirmPayment } from '@/lib/utils/stripe'

export type EnablePremiumGeoDbAddonResult = 'enabled' | 'already_active'

async function refreshPremiumGeoAddonQueries(
  queryClient: QueryClient,
  projectId: string,
  organizationId?: string,
) {
  await Promise.all([
    queryClient.refetchQueries({
      queryKey: projectAddonsQueryOptions(projectId).queryKey,
    }),
    queryClient.refetchQueries({
      queryKey: projectAddonPriceQueryOptions(
        projectId,
        ADDON_KEY_PREMIUM_GEO_DB,
      ).queryKey,
    }),
    queryClient.refetchQueries({ queryKey: ['project', projectId] }),
    ...(organizationId
      ? [refetchOrganizationBillingQueries(queryClient, organizationId)]
      : []),
  ])
}

async function confirmPremiumGeoAddonPayment(
  projectId: string,
  addonId: string,
) {
  try {
    await sdk.forConsole.projects.confirmAddonPayment({
      projectId,
      addonId,
    })
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
 * Enables the project-scoped Premium Geo DB addon (payment + confirm when required).
 */
export async function enablePremiumGeoDbAddon(params: {
  projectId: string
  organizationId: string
  paymentMethodId: string | null | undefined
  missingPaymentMethodMessage: string
  queryClient?: QueryClient
}): Promise<EnablePremiumGeoDbAddonResult> {
  const {
    projectId,
    organizationId,
    paymentMethodId,
    missingPaymentMethodMessage,
    queryClient,
  } = params

  try {
    const result = (await sdk.forConsole.projects.createPremiumGeoDBAddon({
      projectId,
    })) as Models.Addon | Models.PaymentAuthentication

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
      await confirmPremiumGeoAddonPayment(projectId, result.addonId)
    }

    if (queryClient) {
      await refreshPremiumGeoAddonQueries(
        queryClient,
        projectId,
        organizationId,
      )
    }

    return 'enabled'
  } catch (error) {
    const candidate = error as { code?: number }
    if (candidate?.code === 409) {
      if (queryClient) {
        await refreshPremiumGeoAddonQueries(
          queryClient,
          projectId,
          organizationId,
        )
      }
      return 'already_active'
    }
    throw error
  }
}
