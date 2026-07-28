'use client'

import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  ADDON_KEY_BAA,
  isPaymentAuthentication,
  resolveStripeProviderMethodId,
} from '@/lib/billing/addons'
import {
  organizationAddonPriceQueryOptions,
  organizationAddonsQueryOptions,
  useOrganizationAddonPrice,
  useOrganizationById,
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
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { useT } from '@/lib/i18n/translate'

type EnableBaaDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationId: string
  onEnabled?: () => void
}

export function EnableBaaDialog({
  open,
  onOpenChange,
  organizationId,
  onEnabled,
}: EnableBaaDialogProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { organization } = useOrganizationById(organizationId)
  const { addonPrice } = useOrganizationAddonPrice(
    open ? organizationId : null,
    ADDON_KEY_BAA,
  )
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const refreshAddonQueries = async () => {
    await Promise.all([
      queryClient.refetchQueries({
        queryKey: organizationAddonsQueryOptions(organizationId).queryKey,
      }),
      queryClient.refetchQueries({
        queryKey: organizationAddonPriceQueryOptions(
          organizationId,
          ADDON_KEY_BAA,
        ).queryKey,
      }),
      queryClient.refetchQueries({ queryKey: ['organization', organizationId] }),
      queryClient.refetchQueries({
        queryKey: ['billing-aggregation', 'organization'],
      }),
    ])
  }

  const handleEnable = async () => {
    setSubmitting(true)
    setError(null)
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
        try {
          await sdk.forConsole.organizations.confirmAddonPayment({
            organizationId,
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
      toast.success(t('BAA addon has been enabled'))
      onOpenChange(false)
      onEnabled?.()
    } catch (enableError) {
      const candidate = enableError as { code?: number }
      if (candidate?.code === 409) {
        await refreshAddonQueries()
        toast.success(t('BAA addon is already active for your organization'))
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
          <DialogTitle>{t('HIPAA BAA')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {addonPrice
              ? t(
                  'By clicking Accept & Enable, the monthly addon amount will be added to your subscription and your payment method will be charged the prorated amount immediately for the remaining days in your billing cycle.',
                )
              : t(
                  'By clicking Accept & Enable, you confirm acceptance of the Business Associate Agreement and related terms.',
                )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <p className="text-[13px] text-muted-foreground">
            {t(
              "Your action confirms acceptance of Appwrite's Business Associate Agreement and related terms.",
            )}{' '}
            <MarketingSiteLink
              className="font-medium text-foreground underline underline-offset-2"
              href="/baa"
              target="_blank"
              rel="noopener noreferrer"
            >
              {t('View BAA')}
            </MarketingSiteLink>
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
          <Button
            disabled={submitting || !addonPrice}
            onClick={() => void handleEnable()}
          >
            {t('Accept & Enable')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
