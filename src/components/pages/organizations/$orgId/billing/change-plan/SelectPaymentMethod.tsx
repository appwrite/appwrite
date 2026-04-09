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
import { formatPaymentMethodSummary } from '../utils'
import { InlinePaymentForm } from './InlinePaymentForm'
import type { Models } from '@appwrite.io/console'

interface SelectPaymentMethodProps {
  paymentMethods: Models.PaymentMethod[]
  selectedPaymentMethodId?: string
  onPaymentMethodSelect: (paymentMethodId: string) => void
  onAddPaymentMethod: () => void
  taxId: string
  onTaxIdChange: (taxId: string) => void
  onAddCredits: () => void
  organizationId?: string
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
  organizationId,
  onPaymentMethodAdded,
}: SelectPaymentMethodProps) {
  // Filter to only show completed cards (with last4)
  const completedPaymentMethods = paymentMethods.filter((pm) => pm.last4)

  // Show inline form only when dropdown is empty (no payment methods)
  const showInlineForm = completedPaymentMethods.length === 0

  const getDisplayText = (method: Models.PaymentMethod) =>
    formatPaymentMethodSummary(method, { includeExpiry: true })

  const handlePaymentMethodAdded = () => {
    onPaymentMethodAdded?.()
  }

  return (
    <div className="space-y-4">
      {/* Inline Payment Form - only show when dropdown is empty */}
      {showInlineForm ? (
        <InlinePaymentForm
          organizationId={organizationId}
          onSuccess={handlePaymentMethodAdded}
        />
      ) : (
        <>
          {/* Payment Method Dropdown */}
          <div>
            <Label
              htmlFor="payment-method"
              className="text-[13px] font-medium mb-2 block"
            >
              Payment method
            </Label>
            <Select
              value={selectedPaymentMethodId || undefined}
              onValueChange={onPaymentMethodSelect}
            >
              <SelectTrigger id="payment-method" className="h-9 text-[13px]">
                <SelectValue placeholder="Select payment method" />
              </SelectTrigger>
              <SelectContent className="z-[9999]">
                {completedPaymentMethods.map((method) => (
                  <SelectItem key={method.$id} value={method.$id}>
                    {getDisplayText(method)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
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
        </>
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
    </div>
  )
}
