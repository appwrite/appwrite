import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { CreditCard, Plus, Ticket } from 'lucide-react'
import { cn } from '@/lib/utils'
import { formatCardExpiry, maskCardNumber } from '../utils'
import type { Models } from '@appwrite.io/console'

interface SelectPaymentMethodProps {
  paymentMethods: Models.PaymentMethod[]
  selectedPaymentMethodId?: string
  onPaymentMethodSelect: (paymentMethodId: string) => void
  onAddPaymentMethod: () => void
  taxId: string
  onTaxIdChange: (taxId: string) => void
  onAddCredits: () => void
}

export function SelectPaymentMethod({
  paymentMethods,
  selectedPaymentMethodId,
  onPaymentMethodSelect,
  onAddPaymentMethod,
  taxId,
  onTaxIdChange,
  onAddCredits,
}: SelectPaymentMethodProps) {
  // Filter to only show completed cards (with last4)
  const completedPaymentMethods = paymentMethods.filter((pm) => pm.last4)

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Payment Method
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Select a payment method for this plan change.
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        {/* Payment Method Selection */}
        {completedPaymentMethods.length > 0 ? (
          <RadioGroup
            value={selectedPaymentMethodId || undefined}
            onValueChange={onPaymentMethodSelect}
            className="space-y-3"
          >
            {completedPaymentMethods.map((method) => {
              const isSelected = selectedPaymentMethodId === method.$id
              const expiry = method.expiryMonth && method.expiryYear
                ? formatCardExpiry(method.expiryMonth, method.expiryYear)
                : null

              return (
                <div
                  key={method.$id}
                  className={cn(
                    'flex items-start space-x-3 rounded-lg border p-3 transition-colors',
                    isSelected
                      ? 'border-primary bg-primary/5'
                      : 'border-border hover:bg-muted/50',
                  )}
                >
                  <RadioGroupItem
                    value={method.$id}
                    id={method.$id}
                    className="mt-1"
                  />
                  <Label
                    htmlFor={method.$id}
                    className="flex-1 cursor-pointer"
                  >
                    <div className="flex items-center gap-2">
                      <CreditCard className="h-4 w-4 text-muted-foreground" />
                      <div>
                        <div className="text-[13px] font-medium text-foreground">
                          {method.name || 'Card'}
                        </div>
                        {method.last4 && (
                          <div className="text-[12px] text-muted-foreground mt-0.5">
                            {maskCardNumber(method.last4)}
                            {expiry && ` • Expires ${expiry}`}
                          </div>
                        )}
                      </div>
                    </div>
                  </Label>
                </div>
              )
            })}
          </RadioGroup>
        ) : (
          <div className="text-center py-6">
            <p className="text-[13px] text-muted-foreground mb-4">
              No payment methods available
            </p>
            <Button
              variant="outline"
              size="sm"
              onClick={onAddPaymentMethod}
            >
              <Plus className="mr-1.5 h-4 w-4" />
              Add Payment Method
            </Button>
          </div>
        )}

        {/* Add Payment Method Button */}
        {completedPaymentMethods.length > 0 && (
          <Button
            variant="outline"
            size="sm"
            className="w-full"
            onClick={onAddPaymentMethod}
          >
            <Plus className="mr-1.5 h-4 w-4" />
            Add New Payment Method
          </Button>
        )}

        {/* Tax ID */}
        <div className="pt-4 border-t border-border">
          <Label htmlFor="tax-id" className="text-[13px] font-medium">
            Tax ID (Optional)
          </Label>
          <Input
            id="tax-id"
            value={taxId}
            onChange={(e) => onTaxIdChange(e.target.value)}
            placeholder="Enter tax identification number"
            className="mt-2 h-9 text-[13px]"
          />
          <p className="text-[12px] text-muted-foreground mt-1">
            For business accounts, enter your tax identification number
          </p>
        </div>

        {/* Add Credits Button */}
        <div className="pt-2">
          <Button
            variant="ghost"
            size="sm"
            className="w-full"
            onClick={onAddCredits}
          >
            <Ticket className="mr-1.5 h-4 w-4" />
            Add Credits
          </Button>
        </div>
      </div>
    </div>
  )
}
