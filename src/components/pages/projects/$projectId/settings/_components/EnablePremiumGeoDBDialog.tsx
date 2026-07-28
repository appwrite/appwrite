'use client'

import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  ADDON_KEY_PREMIUM_GEO_DB,
  isPaymentAuthentication,
  resolveStripeProviderMethodId,
} from '@/lib/billing/addons'
import {
  projectAddonPriceQueryOptions,
  projectAddonsQueryOptions,
  useOrganizationById,
  useProject,
  useProjectAddonPrice,
} from '@/lib/react-query/hooks'
import { confirmPayment } from '@/lib/utils/stripe'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useT } from '@/lib/i18n/translate'

type EnablePremiumGeoDBDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  onEnabled?: () => void
}

export function EnablePremiumGeoDBDialog({
  open,
  onOpenChange,
  projectId,
  onEnabled,
}: EnablePremiumGeoDBDialogProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { project } = useProject(projectId)
  const { organization } = useOrganizationById(project?.teamId)
  const { addonPrice } = useProjectAddonPrice(
    open ? projectId : null,
    ADDON_KEY_PREMIUM_GEO_DB,
  )
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const refreshAddonQueries = async () => {
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
      queryClient.refetchQueries({
        queryKey: ['billing-aggregation', 'organization'],
      }),
    ])
  }

  const handleEnable = async () => {
    setSubmitting(true)
    setError(null)
    try {
      const result = (await sdk.forConsole.projects.createPremiumGeoDBAddon({
        projectId,
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
        try {
          await sdk.forConsole.projects.confirmAddonPayment({
            projectId,
            addonId: result.addonId,
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

      await refreshAddonQueries()
      toast.success(t('Premium Geo DB addon has been enabled'))
      onOpenChange(false)
      onEnabled?.()
    } catch (enableError) {
      const candidate = enableError as { code?: number }
      if (candidate?.code === 409) {
        await refreshAddonQueries()
        toast.success(t('Premium Geo DB addon is already active for this project'))
        onOpenChange(false)
        onEnabled?.()
        return
      }
      setError(getErrorMessage(enableError))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t('Enable Premium Geo DB')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {addonPrice
              ? t(
                  'By clicking Enable, the monthly addon amount will be added to your subscription and your payment method will be charged the prorated amount immediately for the remaining days in your billing cycle.',
                )
              : t(
                  'By clicking Enable, your payment method will be charged for the prorated amount for the remaining days in your billing cycle, and the addon will be added to this project subscription for future cycles.',
                )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <p className="text-[13px] text-muted-foreground">
            {t(
              'Premium Geo DB enriches session and request data with premium geolocation details including timezone, postal code, ISP, connection type, and organization.',
            )}
          </p>
          {addonPrice ? (
            <div className="rounded-lg border border-border p-4 space-y-3">
              <div className="flex items-center justify-between gap-3 text-[13px]">
                <span>{addonPrice.name}</span>
                <span className="tabular-nums">
                  {formatCurrency(addonPrice.monthlyPrice, addonPrice.currency)}{' '}
                  / {t('month')}
                </span>
              </div>
              <div className="border-t border-border" />
              <div className="flex items-center justify-between gap-3 text-[13px] font-medium">
                <span>{t('Due today (prorated)')}</span>
                <span className="tabular-nums">
                  {formatCurrency(
                    addonPrice.proratedAmount,
                    addonPrice.currency,
                  )}
                </span>
              </div>
              <p className="text-[12px] text-muted-foreground text-end">
                {t('* Plus applicable tax and fees')}
              </p>
            </div>
          ) : null}
          {error ? (
            <p className="text-[13px] text-destructive">{error}</p>
          ) : null}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            disabled={submitting}
            onClick={() => onOpenChange(false)}
          >
            {t('Cancel')}
          </Button>
          <Button disabled={submitting} onClick={() => void handleEnable()}>
            {t('Enable')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
