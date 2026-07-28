'use client'

import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  ADDON_KEY_BAA,
  findActiveOrPendingAddon,
  getAddonConfirmSearchParams,
  hasUpgradeablePlanWithAddon,
  isAddonScheduledForRemoval,
  isPaymentAuthentication,
  resolveStripeProviderMethodId,
} from '@/lib/billing/addons'
import {
  organizationAddonsQueryOptions,
  useBillingPlans,
  useOrganizationAddonPrice,
  useOrganizationAddons,
  useOrganizationById,
  useOrganizationPlan,
} from '@/lib/react-query/hooks'
import { navigateToUpgradeWizard } from '@/lib/open-upgrade-wizard'
import { confirmPayment } from '@/lib/utils/stripe'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { EnableBaaDialog } from './EnableBaaDialog'
import { DisableBaaDialog } from './DisableBaaDialog'

type BaaSettingsCardProps = {
  organizationId: string
}

export function BaaSettingsCard({ organizationId }: BaaSettingsCardProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const { organization } = useOrganizationById(organizationId)
  const { plan } = useOrganizationPlan(organizationId)
  const { plans } = useBillingPlans()
  const { addons } = useOrganizationAddons(
    features.billing ? organizationId : null,
  )
  const { addonPrice } = useOrganizationAddonPrice(
    features.billing ? organizationId : null,
    ADDON_KEY_BAA,
  )
  const [showEnable, setShowEnable] = useState(false)
  const [showDisable, setShowDisable] = useState(false)
  const [reEnabling, setReEnabling] = useState(false)
  const [refreshing, setRefreshing] = useState(false)
  const confirmHandledRef = useRef(false)

  const planSupportsBaa = plan?.supportedAddons?.baa === true
  const canUpgradeToBaa =
    !planSupportsBaa && hasUpgradeablePlanWithAddon(plan, plans, 'baa')
  const baaAddon = useMemo(
    () => findActiveOrPendingAddon(addons, ADDON_KEY_BAA),
    [addons],
  )
  const isPending = baaAddon?.status === 'pending'
  const isActive = baaAddon?.status === 'active'
  const isScheduledForRemoval = isAddonScheduledForRemoval(baaAddon)
  const monthlyPriceLabel = addonPrice
    ? formatCurrency(addonPrice.monthlyPrice, addonPrice.currency)
    : formatCurrency(350)

  const confirmAddon = async (addonId: string) => {
    try {
      await sdk.forConsole.organizations.confirmAddonPayment({
        organizationId,
        addonId,
      })
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: organizationAddonsQueryOptions(organizationId).queryKey,
        }),
        queryClient.refetchQueries({
          queryKey: ['organization', organizationId],
        }),
      ])
      toast.success(t('BAA addon has been enabled'))
    } catch (error) {
      const candidate = error as { type?: string; code?: number; message?: string }
      if (candidate?.type === 'addon_not_found' || candidate?.code === 404) {
        await Promise.all([
          queryClient.refetchQueries({
            queryKey: organizationAddonsQueryOptions(organizationId).queryKey,
          }),
          queryClient.refetchQueries({
            queryKey: ['organization', organizationId],
          }),
        ])
        toast.success(t('BAA addon has been enabled'))
        return
      }
      toast.error(getErrorMessage(error))
    }
  }

  useEffect(() => {
    if (!features.billing || typeof window === 'undefined') return
    if (confirmHandledRef.current) return

    const { type, addonId: searchAddonId } = getAddonConfirmSearchParams(
      window.location.search,
    )
    if (type !== 'confirm-addon') return
    confirmHandledRef.current = true

    void (async () => {
      let addonId = searchAddonId
      if (!addonId || addonId === 'undefined') {
        try {
          const addonList = await sdk.forConsole.organizations.listAddons({
            organizationId,
          })
          addonId =
            findActiveOrPendingAddon(addonList.addons, ADDON_KEY_BAA)?.$id ??
            null
        } catch (error) {
          toast.error(
            getErrorMessage(error) ||
              t('Unable to verify BAA addon status. Please retry.'),
          )
          addonId = null
        }
      }

      if (addonId) {
        await confirmAddon(addonId)
      }

      void navigate({
        to: '/organizations/$orgId/settings/compliance',
        params: { orgId: organizationId },
        replace: true,
      })
    })()
    // eslint-disable-next-line react-hooks/exhaustive-deps -- run once for return URL
  }, [features.billing, organizationId])

  if (!features.billing) return null

  const handleRefresh = async () => {
    if (!baaAddon) return
    setRefreshing(true)
    try {
      await confirmAddon(baaAddon.$id)
    } finally {
      setRefreshing(false)
    }
  }

  const handleReEnable = async () => {
    setReEnabling(true)
    try {
      const result = (await sdk.forConsole.organizations.createBaaAddon({
        organizationId,
      })) as Models.Addon | Models.PaymentAuthentication

      if (isPaymentAuthentication(result)) {
        const paymentMethodId = organization?.paymentMethodId
        if (!paymentMethodId || !organization?.$id) {
          throw new Error(
            t('Add a payment method to your organization before enabling this addon.'),
          )
        }
        const providerMethodId = await resolveStripeProviderMethodId({
          organizationId: organization.$id,
          paymentMethodId,
        })
        await confirmPayment({
          clientSecret: result.clientSecret,
          paymentMethod: providerMethodId,
        })
        await confirmAddon(result.addonId)
        return
      }

      await Promise.all([
        queryClient.refetchQueries({
          queryKey: organizationAddonsQueryOptions(organizationId).queryKey,
        }),
        queryClient.refetchQueries({
          queryKey: ['organization', organizationId],
        }),
      ])
      toast.success(t('BAA addon has been re-enabled'))
    } catch (error) {
      toast.error(getErrorMessage(error))
    } finally {
      setReEnabling(false)
    }
  }

  const statusBadge = (() => {
    if (!planSupportsBaa) {
      return (
        <Badge variant="info" className="text-[10px] shrink-0">
          {t('Unavailable')}
        </Badge>
      )
    }
    if (isPending) {
      return (
        <Badge variant="warning" className="text-[10px] shrink-0">
          {t('Payment pending')}
        </Badge>
      )
    }
    if (isActive && isScheduledForRemoval) {
      return (
        <Badge variant="warning" className="text-[10px] shrink-0">
          {t('Scheduled for removal')}
        </Badge>
      )
    }
    if (isActive) {
      return (
        <Badge variant="success" className="text-[10px] shrink-0">
          {t('Active')}
        </Badge>
      )
    }
    return (
      <Badge variant="info" className="text-[10px] shrink-0">
        {t('Not enabled')}
      </Badge>
    )
  })()

  const statusCopy = (() => {
    if (!planSupportsBaa && canUpgradeToBaa) {
      return t(
        'BAA is not available on your current plan. Upgrade your plan to enable it.',
      )
    }
    if (!planSupportsBaa) {
      return t('BAA is not available on your current plan.')
    }
    if (isPending) {
      return t(
        "A payment is awaiting confirmation. If you've completed authentication, click refresh to check the payment status.",
      )
    }
    if (isActive && isScheduledForRemoval) {
      return t(
        'BAA will be removed at the end of your current billing cycle.',
      )
    }
    if (isActive) {
      return null
    }
    return t(
      'Enable BAA for your organization. This addon costs {price}/month, prorated for your current billing cycle.',
    ).replace('{price}', monthlyPriceLabel)
  })()

  const footerAction = (() => {
    if (!planSupportsBaa && canUpgradeToBaa) {
      return (
        <Button
          size="sm"
          className="h-9 text-[13px]"
          onClick={() => navigateToUpgradeWizard(navigate, organizationId)}
        >
          {t('Upgrade plan')}
        </Button>
      )
    }
    if (!planSupportsBaa) return null
    if (isPending) {
      return (
        <Button
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          disabled={refreshing}
          onClick={() => void handleRefresh()}
        >
          {t('Refresh')}
        </Button>
      )
    }
    if (isActive && isScheduledForRemoval) {
      return (
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={reEnabling}
          onClick={() => void handleReEnable()}
        >
          {t('Keep BAA')}
        </Button>
      )
    }
    if (isActive) {
      return (
        <Button
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          onClick={() => setShowDisable(true)}
        >
          {t('Disable')}
        </Button>
      )
    }
    return (
      <Button
        size="sm"
        className="h-9 text-[13px]"
        onClick={() => setShowEnable(true)}
      >
        {t('Enable BAA')}
      </Button>
    )
  })()

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Business associate agreement (BAA)')}
            </h3>
            {statusBadge}
          </div>
        </div>

        <div className="border-t border-border" />

        <div className="px-6 py-4 space-y-3">
          <p className="text-[13px] text-muted-foreground max-w-2xl">
            {t(
              'A BAA is required under HIPAA when Appwrite handles Protected Health Information (PHI) for your organization. Enable it if you process, store, or transmit health data for US patients.',
            )}
          </p>
          {statusCopy ? (
            <p className="text-[13px] text-muted-foreground">{statusCopy}</p>
          ) : null}
        </div>

        {footerAction ? (
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-wrap items-center gap-3">
            {footerAction}
            {isActive && !isScheduledForRemoval && monthlyPriceLabel ? (
              <span className="text-[13px] text-muted-foreground">
                {t('{price}/month').replace('{price}', monthlyPriceLabel)}
              </span>
            ) : null}
          </div>
        ) : null}
      </div>

      <EnableBaaDialog
        open={showEnable}
        onOpenChange={setShowEnable}
        organizationId={organizationId}
      />
      {baaAddon ? (
        <DisableBaaDialog
          open={showDisable}
          onOpenChange={setShowDisable}
          organizationId={organizationId}
          addonId={baaAddon.$id}
        />
      ) : null}
    </>
  )
}
