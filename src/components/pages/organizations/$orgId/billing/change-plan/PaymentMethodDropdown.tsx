import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Plus, Ticket } from 'lucide-react'
import type { Models } from '@appwrite.io/console'

interface PaymentMethodDropdownProps {
  paymentMethods: Models.PaymentMethod[]
  selectedPaymentMethodId?: string
  onPaymentMethodSelect: (paymentMethodId: string) => void
  onAddPaymentMethod: () => void
  onAddCredits: () => void
}

export function PaymentMethodDropdown({
  paymentMethods,
  selectedPaymentMethodId,
  onPaymentMethodSelect,
  onAddPaymentMethod,
  onAddCredits,
}: PaymentMethodDropdownProps) {
  // Filter to only show completed cards (with last4)
  const completedPaymentMethods = paymentMethods.filter((pm) => pm.last4)

  const selectedMethod = completedPaymentMethods.find(
    (pm) => pm.$id === selectedPaymentMethodId,
  )

  const getDisplayText = (method: Models.PaymentMethod) => {
    if (!method.last4) return method.name || 'Card'
    return `${method.name || 'Card'} ending in ${method.last4}`
  }

  return (
    <div className="space-y-3">
      {/* Payment Method Dropdown */}
      <div>
        <label className="text-[13px] font-medium text-foreground mb-2 block">
          Payment method
        </label>
        {completedPaymentMethods.length > 0 ? (
          <Select
            value={selectedPaymentMethodId || undefined}
            onValueChange={onPaymentMethodSelect}
          >
            <SelectTrigger className="h-9 text-[13px]">
              <SelectValue placeholder="Select payment method">
                {selectedMethod
                  ? getDisplayText(selectedMethod)
                  : 'Select payment method'}
              </SelectValue>
            </SelectTrigger>
            <SelectContent>
              {completedPaymentMethods.map((method) => (
                <SelectItem key={method.$id} value={method.$id}>
                  {getDisplayText(method)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : (
          <div className="rounded-md border border-border bg-background px-3 py-2 text-[13px] text-muted-foreground">
            No payment methods available
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
          <Plus className="mr-1.5 h-4 w-4" />
          Add payment method
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="h-8 text-[13px]"
          onClick={onAddCredits}
        >
          <Ticket className="mr-1.5 h-4 w-4" />
          Add credits
        </Button>
      </div>
    </div>
  )
}
