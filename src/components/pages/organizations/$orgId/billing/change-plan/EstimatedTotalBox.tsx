import { useState } from 'react'
import { BillingPlan } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { X, Ticket } from 'lucide-react'
import { formatCurrency } from '../utils'
import type { Models } from '@appwrite.io/console'

interface EstimatedTotalBoxProps {
  estimation: any | null
  isLoading: boolean
  selectedPlan: BillingPlan | null
  billingPlans: Record<string, any>
  coupon: Models.Coupon | null
  onCouponRemove: () => void
  budget?: number
  onBudgetChange: (budget: number | undefined) => void
}

export function EstimatedTotalBox({
  estimation,
  isLoading,
  selectedPlan,
  billingPlans,
  coupon,
  onCouponRemove,
  budget,
  onBudgetChange,
}: EstimatedTotalBoxProps) {
  const [budgetEnabled, setBudgetEnabled] = useState(false)
  const [budgetValue, setBudgetValue] = useState<string>('')

  const handleBudgetToggle = (enabled: boolean) => {
    setBudgetEnabled(enabled)
    if (!enabled) {
      setBudgetValue('')
      onBudgetChange(undefined)
    }
  }

  const handleBudgetChange = (value: string) => {
    setBudgetValue(value)
    const numValue = parseFloat(value)
    if (!isNaN(numValue) && numValue > 0) {
      onBudgetChange(numValue)
    } else {
      onBudgetChange(undefined)
    }
  }

  if (isLoading) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            Loading estimation...
          </p>
        </div>
      </div>
    )
  }

  if (!estimation) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            Unable to load estimation
          </p>
        </div>
      </div>
    )
  }

  // Map API response structure to component expectations
  // API returns: { items: [{ label, value }], amount, grossAmount, discount, credits, discounts: [] }
  // Component expects: { lineItems: [{ name/description, amount }], totalDue, discounts: [], credits: [] }
  const items = estimation.items || []
  const lineItems = items.map((item: any) => ({
    name: item.label,
    description: item.label,
    amount: item.value || 0,
    currency: 'USD',
  }))

  const discounts = estimation.discounts || []
  const discountAmount = estimation.discount || 0
  const creditsAmount = estimation.credits || 0
  const organizationCredits = estimation.organizationCredits || 0
  const totalDue = estimation.amount || 0
  const grossAmount = estimation.grossAmount || 0
  const recurringCharge = estimation.recurringCharge || 0

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden sticky top-6">
      <div className="px-6 py-4">
        <h3 className="text-[13px] font-semibold text-foreground">
          Estimated total
        </h3>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        {/* Line Items */}
        {lineItems.length > 0 && (
          <div className="space-y-2">
            {lineItems.map((item: any, index: number) => (
              <div
                key={index}
                className="flex items-center justify-between text-[13px]"
              >
                <span className="text-muted-foreground">
                  {item.name || item.description}
                </span>
                <span className="font-medium text-foreground">
                  {formatCurrency(item.amount || 0, item.currency || 'USD')}
                </span>
              </div>
            ))}
          </div>
        )}

        {/* Discounts */}
        {(discounts.length > 0 || discountAmount > 0) && (
          <div className="space-y-2 pt-2 border-t border-border">
            {discounts.map((discount: any, index: number) => (
              <div
                key={index}
                className="flex items-center justify-between text-[13px]"
              >
                <span className="text-muted-foreground">
                  Discount:{' '}
                  {discount.name || discount.description || 'Discount'}
                </span>
                <span className="font-medium text-green-600 dark:text-green-400">
                  -
                  {formatCurrency(
                    discount.amount || 0,
                    discount.currency || 'USD',
                  )}
                </span>
              </div>
            ))}
            {discountAmount > 0 && discounts.length === 0 && (
              <div className="flex items-center justify-between text-[13px]">
                <span className="text-muted-foreground">Discount</span>
                <span className="font-medium text-green-600 dark:text-green-400">
                  -{formatCurrency(discountAmount, 'USD')}
                </span>
              </div>
            )}
          </div>
        )}

        {/* Credits */}
        {(creditsAmount > 0 || organizationCredits > 0) && (
          <div className="space-y-2 pt-2 border-t border-border">
            {creditsAmount > 0 && (
              <div className="flex items-center justify-between text-[13px]">
                <span className="text-muted-foreground">Credits</span>
                <span className="font-medium text-green-600 dark:text-green-400">
                  -{formatCurrency(creditsAmount, 'USD')}
                </span>
              </div>
            )}
            {organizationCredits > 0 && (
              <div className="flex items-center justify-between text-[13px]">
                <span className="text-muted-foreground">
                  Organization Credits
                </span>
                <span className="font-medium text-green-600 dark:text-green-400">
                  -{formatCurrency(organizationCredits, 'USD')}
                </span>
              </div>
            )}
          </div>
        )}

        {/* Coupon Applied */}
        {coupon && (
          <div className="pt-2 border-t border-border">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Ticket className="h-4 w-4 text-muted-foreground" />
                <span className="text-[13px] text-muted-foreground">
                  Coupon: {coupon.code}
                </span>
              </div>
              <Button
                variant="ghost"
                size="sm"
                className="h-6 w-6 p-0"
                onClick={onCouponRemove}
              >
                <X className="h-3.5 w-3.5" />
              </Button>
            </div>
          </div>
        )}

        {/* Total Due */}
        {totalDue > 0 && (
          <div className="pt-2 border-t border-border">
            <div className="flex items-center justify-between">
              <span className="text-[13px] font-medium text-foreground">
                Total Due
              </span>
              <span className="text-[15px] font-semibold text-foreground">
                {formatCurrency(totalDue, 'USD')}
              </span>
            </div>
          </div>
        )}

        {/* Recurring Charge */}
        {recurringCharge > 0 && (
          <div className="pt-2">
            <div className="flex items-center justify-between">
              <span className="text-[13px] text-muted-foreground">
                Recurring Charge
              </span>
              <span className="text-[13px] font-medium text-foreground">
                {formatCurrency(recurringCharge, estimation.currency || 'USD')}
                /month
              </span>
            </div>
          </div>
        )}

        {/* Budget Cap */}
        {estimation.budgetEnabled !== false && (
          <div className="pt-4 border-t border-border space-y-3">
            <div className="flex items-center justify-between">
              <Label htmlFor="budget-cap" className="text-[13px] font-medium">
                Budget Cap
              </Label>
              <Switch
                id="budget-cap"
                checked={budgetEnabled}
                onCheckedChange={handleBudgetToggle}
              />
            </div>
            {budgetEnabled && (
              <div>
                <div className="relative">
                  <span className="absolute left-3 top-1/2 -translate-y-1/2 text-[13px] text-muted-foreground">
                    $
                  </span>
                  <Input
                    id="budget-amount"
                    type="number"
                    placeholder="0.00"
                    value={budgetValue}
                    onChange={(e) => handleBudgetChange(e.target.value)}
                    className="h-9 text-[13px] pl-7"
                    min="0"
                    step="0.01"
                  />
                </div>
                <p className="text-[12px] text-muted-foreground mt-1">
                  Set a monthly spending limit
                </p>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
