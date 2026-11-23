import { useEffect, useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { PaymentMethodDropdown } from './change-plan/PaymentMethodDropdown'
import { PaymentModal } from './Payment'
import { asOrganizationPaymentRefs, formatCurrency, formatDate } from './utils'
import {
  useOrganizationById,
  usePaymentMethods,
  useRetryInvoicePayment,
  useSetOrganizationDefaultPaymentMethod,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { sdk } from '@/lib/appwrite/sdk'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

export type RetryPaymentInvoice = {
  $id: string
  amount?: number
  currency?: string
  dueAt?: string
}

type RetryPaymentProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationId: string
  invoice: RetryPaymentInvoice | null
}

export function RetryPayment({
  open,
  onOpenChange,
  organizationId,
  invoice,
}: RetryPaymentProps) {
  const t = useT()
  const { organization } = useOrganizationById(organizationId)
  const { paymentMethods, refetch: refetchPaymentMethods } = usePaymentMethods({
    enabled: open,
  })
  const retryPaymentMutation = useRetryInvoicePayment()
  const setDefaultPaymentMethodMutation =
    useSetOrganizationDefaultPaymentMethod()

  const [paymentMethodId, setPaymentMethodId] = useState<string | undefined>()
  const [setAsDefault, setSetAsDefault] = useState(false)
  const [addPaymentModalOpen, setAddPaymentModalOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const orgRefs = organization ? asOrganizationPaymentRefs(organization) : null
  const completedMethods = paymentMethods.filter((method) => !!method.last4)

  useEffect(() => {
    if (!open) {
      setPaymentMethodId(undefined)
      setSetAsDefault(false)
      setError(null)
    }
  }, [open])

  useEffect(() => {
    if (!open || paymentMethodId) return

    const defaultId = orgRefs?.paymentMethodId || undefined
    const backupId = orgRefs?.backupPaymentMethodId || undefined
    const ids = new Set(completedMethods.map((method) => method.$id))

    if (defaultId && ids.has(defaultId)) {
      setPaymentMethodId(defaultId)
      return
    }
    if (backupId && ids.has(backupId)) {
      setPaymentMethodId(backupId)
      return
    }
    if (completedMethods[0]?.$id) {
      setPaymentMethodId(completedMethods[0].$id)
    }
  }, [
    open,
    paymentMethodId,
    orgRefs?.paymentMethodId,
    orgRefs?.backupPaymentMethodId,
    paymentMethods,
  ])

  const amount = invoice?.amount
  const currency = invoice?.currency || 'USD'
  const dueAt = invoice?.dueAt
  const isSubmitting =
    retryPaymentMutation.isPending || setDefaultPaymentMethodMutation.isPending

  const handleViewInvoice = () => {
    if (!invoice) return
    const url = sdk.forConsole.organizations.getInvoiceView({
      organizationId,
      invoiceId: invoice.$id,
    })
    window.open(url, '_blank', 'noopener,noreferrer')
  }

  const handleSubmit = async () => {
    if (!invoice) return
    if (!paymentMethodId) {
      setError(
        t('No payment method available. Please add a payment method first.'),
      )
      return
    }

    setError(null)
    try {
      if (setAsDefault && paymentMethodId !== orgRefs?.paymentMethodId) {
        await setDefaultPaymentMethodMutation.mutateAsync({
          organizationId,
          paymentMethodId,
        })
      }

      const selectedMethod = completedMethods.find(
        (method) => method.$id === paymentMethodId,
      )
      await retryPaymentMutation.mutateAsync({
        organizationId,
        invoiceId: invoice.$id,
        paymentMethodId,
        providerMethodId: selectedMethod?.providerMethodId,
      })

      toast.success(t('Payment has been successfully processed'))
      onOpenChange(false)
    } catch (submitError) {
      setError(getErrorMessage(submitError, t('Failed to retry payment')))
    }
  }

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Retry payment')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Retry your payment to avoid service interruptions with your projects.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4 space-y-4">
            {typeof amount === 'number' ? (
              <p className="text-[13px] text-muted-foreground">
                {t('Amount due')}: {formatCurrency(amount, currency)}
              </p>
            ) : null}
            {dueAt ? (
              <p className="text-[13px] text-muted-foreground">
                {t('Due Date')}: {formatDate(dueAt)}
              </p>
            ) : null}
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 text-[13px]"
              onClick={handleViewInvoice}
              disabled={!invoice}
            >
              {t('View invoice')}
            </Button>

            <PaymentMethodDropdown
              paymentMethods={completedMethods}
              selectedPaymentMethodId={paymentMethodId}
              onPaymentMethodSelect={(id) => {
                setPaymentMethodId(id)
                setError(null)
              }}
              onAddPaymentMethod={() => setAddPaymentModalOpen(true)}
            />

            {completedMethods.length > 0 && (
              <div className="flex items-center gap-2">
                <Checkbox
                  id="retry-set-default"
                  checked={setAsDefault}
                  onCheckedChange={(checked) =>
                    setSetAsDefault(checked === true)
                  }
                  disabled={isSubmitting}
                />
                <Label
                  htmlFor="retry-set-default"
                  className="text-[13px] font-normal cursor-pointer"
                >
                  {t('Set as default payment method')}
                </Label>
              </div>
            )}

            {error ? (
              <p className="text-[13px] text-destructive">{error}</p>
            ) : null}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isSubmitting}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              onClick={handleSubmit}
              disabled={isSubmitting || !paymentMethodId}
            >
              {t('Retry')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <PaymentModal
        open={addPaymentModalOpen}
        onOpenChange={setAddPaymentModalOpen}
        organizationId={organizationId}
        onSuccess={async () => {
          setAddPaymentModalOpen(false)
          const previousIds = new Set(
            completedMethods.map((method) => method.$id),
          )
          const result = await refetchPaymentMethods()
          const methods = (result.data?.paymentMethods ?? []).filter(
            (method) => !!method.last4,
          )
          const added = methods.find((method) => !previousIds.has(method.$id))
          if (added) {
            setPaymentMethodId(added.$id)
          }
        }}
        elevatedForWizard
      />
    </>
  )
}
