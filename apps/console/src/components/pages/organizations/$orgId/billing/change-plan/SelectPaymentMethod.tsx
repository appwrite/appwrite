import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Plus, Ticket } from 'lucide-react'
import { PaymentMethodBrandAvatar } from '@/components/global/shared/PaymentMethodBrandAvatar'
import { formatPaymentMethodSummary } from '../utils'
import type { Models } from '@appwrite.io/console'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

interface SelectPaymentMethodProps {
  paymentMethods: Models.PaymentMethod[]
  selectedPaymentMethodId?: string
  onPaymentMethodSelect: (paymentMethodId: string) => void
  onAddPaymentMethod: () => void
  taxId: string
  onTaxIdChange: (taxId: string) => void
  onAddCredits?: () => void
  showApplyCoupon?: boolean
  onPaymentMethodAdded?: () => void
}

export function SelectPaymentMethod({
  paymentMethods,
  selectedPaymentMethodId,
  onPaymentMethodSelect,
  onAddPaymentMethod,
  taxId,
  onTaxIdChange,
  onAddCredits,
  showApplyCoupon = false,
}: SelectPaymentMethodProps) {
  const t = useT()
  // Filter to only show completed cards (with last4)
  const completedPaymentMethods = paymentMethods.filter((pm) => pm.last4)
  const hasCompletedPaymentMethods = completedPaymentMethods.length > 0

  const getDisplayText = (method: Models.PaymentMethod) =>
    formatPaymentMethodSummary(method, { includeExpiry: true })

  const renderPaymentMethodOption = (method: Models.PaymentMethod) => (
    <span className="flex min-w-0 items-center gap-1.5">
      <PaymentMethodBrandAvatar brand={method.brand} />
      <span className="truncate">{getDisplayText(method)}</span>
    </span>
  )

  return (
    <div className="space-y-4">
      <div>
        <Label
          htmlFor="payment-method"
          className="text-[13px] font-medium mb-2 block"
        >
          {t('Payment method')} <span className="text-destructive">*</span>
        </Label>

        {hasCompletedPaymentMethods ? (
          <>
            <Select
              value={selectedPaymentMethodId || undefined}
              onValueChange={onPaymentMethodSelect}
            >
              <SelectTrigger id="payment-method" className="h-9 text-[13px]">
                <SelectValue placeholder={t('Select payment method')}>
                  {selectedPaymentMethodId
                    ? (() => {
                        const method = completedPaymentMethods.find(
                          (pm) => pm.$id === selectedPaymentMethodId,
                        )
                        return method
                          ? renderPaymentMethodOption(method)
                          : undefined
                      })()
                    : undefined}
                </SelectValue>
              </SelectTrigger>
              <SelectContent className="z-[9999]">
                {completedPaymentMethods.map((method) => (
                  <SelectItem key={method.$id} value={method.$id}>
                    {renderPaymentMethodOption(method)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>

            <div className="mt-3 flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                className="h-8 text-[13px]"
                onClick={onAddPaymentMethod}
                {...analyticsAttrs('upgrade-add-payment')}
              >
                <Plus className="me-1.5 h-4 w-4" />
                {t('Add payment method')}
              </Button>
              {showApplyCoupon && onAddCredits ? (
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8 text-[13px]"
                  onClick={onAddCredits}
                  {...analyticsAttrs('upgrade-apply-coupon')}
                >
                  <Ticket className="me-1.5 h-4 w-4" />
                  {t('Apply coupon')}
                </Button>
              ) : null}
            </div>
          </>
        ) : (
          <div className="rounded-lg border border-border bg-card/50 p-4">
            <p className="text-[13px] text-muted-foreground leading-relaxed">
              {t('Add a payment method to continue with a paid plan.')}
            </p>
            <Button
              variant="outline"
              size="sm"
              className="mt-3 h-8 text-[13px]"
              onClick={onAddPaymentMethod}
              {...analyticsAttrs('upgrade-add-payment')}
            >
              {t('Add payment method')}
            </Button>
          </div>
        )}
      </div>

      {/* Tax ID */}
      <div className="pt-4 border-t border-border">
        <Label htmlFor="tax-id" className="text-[13px] font-medium">
          {t('Tax ID (Optional)')}
        </Label>
        <Input
          id="tax-id"
          value={taxId}
          onChange={(e) => onTaxIdChange(e.target.value)}
          placeholder={t('Enter tax identification number')}
          className="mt-2 h-9 text-[13px]"
        />
        <p className="text-[12px] text-muted-foreground mt-1">
          {t('For business accounts, enter your tax identification number')}
        </p>
      </div>
    </div>
  )
}
