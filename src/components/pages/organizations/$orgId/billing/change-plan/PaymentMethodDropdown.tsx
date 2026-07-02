import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Plus, Ticket } from 'lucide-react'
import { PaymentMethodBrandAvatar } from '@/components/global/shared/PaymentMethodBrandAvatar'
import type { Models } from '@appwrite.io/console'
import { formatPaymentMethodSummary } from '../utils'
import { useT } from '@/lib/i18n/translate'

interface PaymentMethodDropdownProps {
  paymentMethods: Models.PaymentMethod[]
  selectedPaymentMethodId?: string
  onPaymentMethodSelect: (paymentMethodId: string) => void
  onAddPaymentMethod: () => void
  /** Omit when credits cannot be used (e.g. domain checkout). */
  onAddCredits?: () => void
}

export function PaymentMethodDropdown({
  paymentMethods,
  selectedPaymentMethodId,
  onPaymentMethodSelect,
  onAddPaymentMethod,
  onAddCredits,
}: PaymentMethodDropdownProps) {
  const t = useT()
  // Filter to only show completed cards (with last4)
  const completedPaymentMethods = paymentMethods.filter((pm) => pm.last4)

  const getDisplayText = (method: Models.PaymentMethod) =>
    formatPaymentMethodSummary(method)

  const renderPaymentMethodOption = (method: Models.PaymentMethod) => (
    <span className="flex min-w-0 items-center gap-1.5">
      <PaymentMethodBrandAvatar brand={method.brand} />
      <span className="truncate">{getDisplayText(method)}</span>
    </span>
  )

  return (
    <div className="space-y-3">
      {/* Payment Method Dropdown */}
      <div>
        <label className="text-[13px] font-medium text-foreground mb-2 block">
          {t('Payment method')}
        </label>
        {completedPaymentMethods.length > 0 ? (
          <Select
            value={selectedPaymentMethodId || undefined}
            onValueChange={onPaymentMethodSelect}
          >
            <SelectTrigger className="h-9 w-full min-w-0 text-[13px]">
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
            <SelectContent>
              {completedPaymentMethods.map((method) => (
                <SelectItem key={method.$id} value={method.$id}>
                  {renderPaymentMethodOption(method)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : (
          <div className="rounded-md border border-border bg-background px-3 py-2 text-[13px] text-muted-foreground">
            {t('No payment methods available')}
          </div>
        )}
      </div>

      {/* Action Buttons */}
      <div className="flex items-center gap-2">
        <Button
          variant="ghost"
          size="sm"
          className="h-8 text-[13px]"
          onClick={onAddPaymentMethod}
        >
          <Plus className="me-1.5 h-4 w-4" />
          {t('Add payment method')}
        </Button>
        {onAddCredits ? (
          <Button
            variant="ghost"
            size="sm"
            className="h-8 text-[13px]"
            onClick={onAddCredits}
          >
            <Ticket className="me-1.5 h-4 w-4" />
            {t('Add credits')}
          </Button>
        ) : null}
      </div>
    </div>
  )
}
