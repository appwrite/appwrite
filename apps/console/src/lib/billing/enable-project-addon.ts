import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  isPaymentAuthentication,
  resolveStripeProviderMethodId,
} from '@/lib/billing/addons'
import { refetchOrganizationBillingQueries } from '@/lib/billing/refetch-organization-billing-queries'
import {
  projectAddonPriceQueryOptions,
  projectAddonsQueryOptions,
} from '@/lib/react-query/hooks/addons'
import { confirmPayment } from '@/lib/utils/stripe'

export type EnableProjectAddonResult = 'enabled' | 'already_active'

export type EnableProjectAddonParams = {
  projectId: string
  organizationId: string
  paymentMethodId: string | null | undefined
  missingPaymentMethodMessage: string
  queryClient?: QueryClient
}

async function refreshProjectAddonQueries(
  queryClient: QueryClient,
  projectId: string,
  addonKey: string,
  organizationId?: string,
) {
  await Promise.all([
    queryClient.refetchQueries({
      queryKey: projectAddonsQueryOptions(projectId).queryKey,
    }),
    queryClient.refetchQueries({
      queryKey: projectAddonPriceQueryOptions(projectId, addonKey).queryKey,
    }),
    queryClient.refetchQueries({ queryKey: ['project', projectId] }),
    ...(organizationId
      ? [refetchOrganizationBillingQueries(queryClient, organizationId)]
      : []),
  ])
}

async function confirmProjectAddonPayment(projectId: string, addonId: string) {
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
 * Enables a project-scoped addon (payment + confirm when required). `create`
 * asks the API for the addon `addonKey`.
 */
export async function enableProjectAddon(
  params: EnableProjectAddonParams & {
    addonKey: string
    create: (
      projectId: string,
    ) => Promise<Models.Addon | Models.PaymentAuthentication>
  },
): Promise<EnableProjectAddonResult> {
  const {
    projectId,
    organizationId,
    paymentMethodId,
    missingPaymentMethodMessage,
    queryClient,
    addonKey,
    create,
  } = params

  try {
    const result = await create(projectId)

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
      await confirmProjectAddonPayment(projectId, result.addonId)
    }

    if (queryClient) {
      await refreshProjectAddonQueries(
        queryClient,
        projectId,
        addonKey,
        organizationId,
      )
    }

    return 'enabled'
  } catch (error) {
    const candidate = error as { code?: number }
    if (candidate?.code === 409) {
      if (queryClient) {
        await refreshProjectAddonQueries(
          queryClient,
          projectId,
          addonKey,
          organizationId,
        )
      }
      return 'already_active'
    }
    throw error
  }
}
