/**
 * EditPaymentMethodModal Component
 *
 * Modal for updating payment method expiration date.
 */

import { useState, useEffect } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useUpdatePaymentMethod } from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

interface EditPaymentMethodModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  paymentMethod: Models.PaymentMethod
  onSuccess?: () => void
}

// Generate month options (01-12)
const MONTHS = Array.from({ length: 12 }, (_, i) => {
  const month = i + 1
  return {
    value: month.toString().padStart(2, '0'),
    label: month.toString().padStart(2, '0'),
  }
})

// Generate year options (current year to 20 years ahead)
const getYears = () => {
  const currentYear = new Date().getFullYear()
  return Array.from({ length: 21 }, (_, i) => {
    const year = currentYear + i
    return {
      value: year.toString(),
      label: year.toString(),
    }
  })
}

// US States list
const US_STATES = [
  { value: 'AL', label: 'Alabama' },
  { value: 'AK', label: 'Alaska' },
  { value: 'AZ', label: 'Arizona' },
  { value: 'AR', label: 'Arkansas' },
  { value: 'CA', label: 'California' },
  { value: 'CO', label: 'Colorado' },
  { value: 'CT', label: 'Connecticut' },
  { value: 'DE', label: 'Delaware' },
  { value: 'FL', label: 'Florida' },
  { value: 'GA', label: 'Georgia' },
  { value: 'HI', label: 'Hawaii' },
  { value: 'ID', label: 'Idaho' },
  { value: 'IL', label: 'Illinois' },
  { value: 'IN', label: 'Indiana' },
  { value: 'IA', label: 'Iowa' },
  { value: 'KS', label: 'Kansas' },
  { value: 'KY', label: 'Kentucky' },
  { value: 'LA', label: 'Louisiana' },
  { value: 'ME', label: 'Maine' },
  { value: 'MD', label: 'Maryland' },
  { value: 'MA', label: 'Massachusetts' },
  { value: 'MI', label: 'Michigan' },
  { value: 'MN', label: 'Minnesota' },
  { value: 'MS', label: 'Mississippi' },
  { value: 'MO', label: 'Missouri' },
  { value: 'MT', label: 'Montana' },
  { value: 'NE', label: 'Nebraska' },
  { value: 'NV', label: 'Nevada' },
  { value: 'NH', label: 'New Hampshire' },
  { value: 'NJ', label: 'New Jersey' },
  { value: 'NM', label: 'New Mexico' },
  { value: 'NY', label: 'New York' },
  { value: 'NC', label: 'North Carolina' },
  { value: 'ND', label: 'North Dakota' },
  { value: 'OH', label: 'Ohio' },
  { value: 'OK', label: 'Oklahoma' },
  { value: 'OR', label: 'Oregon' },
  { value: 'PA', label: 'Pennsylvania' },
  { value: 'RI', label: 'Rhode Island' },
  { value: 'SC', label: 'South Carolina' },
  { value: 'SD', label: 'South Dakota' },
  { value: 'TN', label: 'Tennessee' },
  { value: 'TX', label: 'Texas' },
  { value: 'UT', label: 'Utah' },
  { value: 'VT', label: 'Vermont' },
  { value: 'VA', label: 'Virginia' },
  { value: 'WA', label: 'Washington' },
  { value: 'WV', label: 'West Virginia' },
  { value: 'WI', label: 'Wisconsin' },
  { value: 'WY', label: 'Wyoming' },
]

export function EditPaymentMethodModal({
  open,
  onOpenChange,
  paymentMethod,
  onSuccess,
}: EditPaymentMethodModalProps) {
  const t = useT()
  const [expiryMonth, setExpiryMonth] = useState<string>(
    paymentMethod.expiryMonth?.toString().padStart(2, '0') || '',
  )
  const [expiryYear, setExpiryYear] = useState<string>(
    paymentMethod.expiryYear?.toString() || '',
  )
  const [state, setState] = useState<string>(paymentMethod.state || '')

  const updatePaymentMethodMutation = useUpdatePaymentMethod()

  // Reset form when modal opens/closes or payment method changes
  useEffect(() => {
    if (open && paymentMethod) {
      setExpiryMonth(
        paymentMethod.expiryMonth?.toString().padStart(2, '0') || '',
      )
      setExpiryYear(paymentMethod.expiryYear?.toString() || '')
      setState(paymentMethod.state || '')
    }
  }, [open, paymentMethod])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (!expiryMonth || !expiryYear) {
      toast.error(t('Please select expiration month and year'))
      return
    }

    // For US cards, state is required
    if (paymentMethod.country === 'US' && !state) {
      toast.error(t('Please select a state'))
      return
    }

    try {
      // State is required by the API - use existing state if not changed
      // For US cards, state should already be set; for others, use existing or empty string
      const finalState = state || paymentMethod.state || ''

      await updatePaymentMethodMutation.mutateAsync({
        paymentMethodId: paymentMethod.$id,
        expiryMonth: parseInt(expiryMonth, 10),
        expiryYear: parseInt(expiryYear, 10),
        state: finalState,
      })

      toast.success(t('Payment method updated'))
      onOpenChange(false)
      onSuccess?.()
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to update payment method'),
      )
    }
  }

  const isLoading = updatePaymentMethodMutation.isPending
  const hasChanges =
    expiryMonth !==
      (paymentMethod.expiryMonth?.toString().padStart(2, '0') || '') ||
    expiryYear !== (paymentMethod.expiryYear?.toString() || '') ||
    state !== (paymentMethod.state || '')

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Update payment method')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Update the expiration date for this payment method.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="expiry-month" className="text-[13px]">
                  {t('Month')}
                </Label>
                <Select
                  value={expiryMonth}
                  onValueChange={setExpiryMonth}
                  disabled={isLoading}
                >
                  <SelectTrigger
                    id="expiry-month"
                    className="h-9 w-full text-[13px]"
                  >
                    <SelectValue placeholder="MM" />
                  </SelectTrigger>
                  <SelectContent>
                    {MONTHS.map((month) => (
                      <SelectItem key={month.value} value={month.value}>
                        {month.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="expiry-year" className="text-[13px]">
                  {t('Year')}
                </Label>
                <Select
                  value={expiryYear}
                  onValueChange={setExpiryYear}
                  disabled={isLoading}
                >
                  <SelectTrigger
                    id="expiry-year"
                    className="h-9 w-full text-[13px]"
                  >
                    <SelectValue placeholder="YYYY" />
                  </SelectTrigger>
                  <SelectContent>
                    {getYears().map((year) => (
                      <SelectItem key={year.value} value={year.value}>
                        {year.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            {paymentMethod.country === 'US' && (
              <div className="space-y-2">
                <Label htmlFor="state" className="text-[13px]">
                  {t('State')}
                </Label>
                <Select
                  value={state}
                  onValueChange={setState}
                  disabled={isLoading}
                >
                  <SelectTrigger id="state" className="h-9 text-[13px]">
                    <SelectValue placeholder={t('Select a state')} />
                  </SelectTrigger>
                  <SelectContent>
                    {US_STATES.map((stateOption) => (
                      <SelectItem
                        key={stateOption.value}
                        value={stateOption.value}
                      >
                        {stateOption.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            )}
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isLoading}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="submit"
              disabled={isLoading || !hasChanges || !expiryMonth || !expiryYear}
            >
              {t('Update')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
