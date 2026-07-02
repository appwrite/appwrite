import { useState } from 'react'
import type { BillingPlanTier } from '@/lib/constants/billing-plan'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { X, Ticket } from 'lucide-react'
import { WarningAlert } from '@/components/global/shared/WarningAlert'
import { formatCurrency } from '../utils'
import { CouponExpirationNotice } from '../CouponExpirationNotice'
import { AppwriteException, type Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

function getEstimationErrorMessage(error: unknown): string {
  if (error instanceof AppwriteException && error.code === 429) {
    return 'Too many estimation requests. Wait a moment, then try again.'
  }

  const message =
    error instanceof Error
      ? error.message
      : typeof error === 'string'
        ? error
        : 'Unknown error'

  if (message.includes('429') || /rate limit/i.test(message)) {
    return 'Too many estimation requests. Wait a moment, then try again.'
  }

  return 'Unable to load estimation. Try again in a moment.'
}

type EstimationLineItem = {
  label?: string
  value?: number
  name?: string
  description?: string
  amount?: number
}

type EstimationPayload = {
  items?: EstimationLineItem[]
  discounts?: EstimationLineItem[]
  amount?: number
  grossAmount?: number
  discount?: number | null
  credits?: number
  organizationCredits?: number
  recurringCharge?: number
  currency?: string
  budgetEnabled?: boolean
  estimation?: EstimationPayload
}

function normalizeEstimationPayload(estimation: unknown): EstimationPayload {
  if (!estimation || typeof estimation !== 'object') {
    return {}
  }

  const data = estimation as EstimationPayload
  if (data.estimation && typeof data.estimation === 'object') {
    return data.estimation
  }

  return data
}

function getEstimationLineLabel(item: EstimationLineItem): string {
  return item.label ?? item.name ?? item.description ?? ''
}

function getEstimationLineAmount(item: EstimationLineItem): number {
  const amount = item.value ?? item.amount ?? 0
  return typeof amount === 'number' && Number.isFinite(amount) ? amount : 0
}

function getEstimationDiscountLines(
  estimationData: EstimationPayload,
): Array<{ label: string; amount: number }> {
  const lines = (estimationData.discounts ?? [])
    .map((entry) => ({
      label: getEstimationLineLabel(entry),
      amount: getEstimationLineAmount(entry),
    }))
    .filter((entry) => entry.amount > 0 && entry.label.length > 0)

  const hasLabel = (pattern: RegExp) =>
    lines.some((line) => pattern.test(line.label))

  const discountScalar =
    typeof estimationData.discount === 'number' && estimationData.discount > 0
      ? estimationData.discount
      : 0
  const creditsScalar =
    typeof estimationData.credits === 'number' && estimationData.credits > 0
      ? estimationData.credits
      : 0
  const organizationCreditsScalar =
    typeof estimationData.organizationCredits === 'number' &&
    estimationData.organizationCredits > 0
      ? estimationData.organizationCredits
      : 0

  if (discountScalar > 0 && !hasLabel(/discount/i)) {
    lines.push({ label: 'Discount', amount: discountScalar })
  }
  if (creditsScalar > 0 && !hasLabel(/credit/i)) {
    lines.push({ label: 'Credits', amount: creditsScalar })
  }
  if (
    organizationCreditsScalar > 0 &&
    !hasLabel(/organization credit/i)
  ) {
    lines.push({
      label: 'Organization credits',
      amount: organizationCreditsScalar,
    })
  }

  return lines
}

const DEFAULT_BUDGET_CAP_USD = 200

function EstimatedTotalSkeleton() {
  const t = useT()
  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden sticky top-6">
      <div className="px-6 py-4">
        <h3 className="text-[13px] font-semibold text-foreground">
          {t('Estimated total')}
        </h3>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        <div className="space-y-2">
          {[0, 1, 2].map((index) => (
            <div
              key={index}
              className="flex items-center justify-between gap-4"
            >
              <Skeleton className="h-4 w-28" />
              <Skeleton className="h-4 w-16 shrink-0" />
            </div>
          ))}
        </div>

        <div className="space-y-2 border-t border-border pt-2">
          <div className="flex items-center justify-between gap-4">
            <Skeleton className="h-4 w-20" />
            <Skeleton className="h-5 w-24 shrink-0" />
          </div>
        </div>

        <div className="flex items-center justify-between gap-4 pt-2">
          <Skeleton className="h-4 w-32" />
          <Skeleton className="h-4 w-20 shrink-0" />
        </div>

        <div className="space-y-3 border-t border-border pt-4">
          <div className="flex items-center justify-between gap-4">
            <Skeleton className="h-4 w-24" />
            <Skeleton className="h-5 w-9 shrink-0 rounded-full" />
          </div>
        </div>
      </div>
    </div>
  )
}

interface EstimatedTotalBoxProps {
  estimation: unknown | null
  isLoading: boolean
  awaitingPaymentMethod?: boolean
  error?: unknown
  onRetry?: () => void
  selectedPlan: BillingPlanTier | null
  billingPlans: Record<string, unknown>
  coupon: Models.Coupon | null
  onCouponRemove: () => void
  budget?: number
  onBudgetChange: (budget: number | undefined) => void
}

export function EstimatedTotalBox({
  estimation,
  isLoading,
  awaitingPaymentMethod = false,
  error,
  onRetry,
  coupon,
  onCouponRemove,
  onBudgetChange,
}: EstimatedTotalBoxProps) {
  const t = useT()
  const [budgetEnabled, setBudgetEnabled] = useState(false)
  const [budgetValue, setBudgetValue] = useState<string>('')

  const handleBudgetToggle = (enabled: boolean) => {
    setBudgetEnabled(enabled)
    if (!enabled) {
      setBudgetValue('')
      onBudgetChange(undefined)
      return
    }
    setBudgetValue(String(DEFAULT_BUDGET_CAP_USD))
    onBudgetChange(DEFAULT_BUDGET_CAP_USD)
  }

  const handleBudgetChange = (value: string) => {
    if (value === '') {
      setBudgetValue('')
      onBudgetChange(undefined)
      return
    }
    if (!/^\d+$/.test(value)) return

    setBudgetValue(value)
    const numValue = parseInt(value, 10)
    if (numValue > 0) {
      onBudgetChange(numValue)
    } else {
      onBudgetChange(undefined)
    }
  }

  if (isLoading && !estimation && !awaitingPaymentMethod) {
    return <EstimatedTotalSkeleton />
  }

  if (awaitingPaymentMethod) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[13px] font-semibold text-foreground">
            {t('Estimated total')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2 leading-relaxed">
            {t('Add a payment method to see your estimated total.')}
          </p>
        </div>
      </div>
    )
  }

  if (!estimation) {
    const message = error
      ? t(getEstimationErrorMessage(error))
      : t('Unable to load estimation.')

    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden sticky top-6">
        <div className="px-6 py-4">
          <h3 className="text-[13px] font-semibold text-foreground">
            {t('Estimated total')}
          </h3>
        </div>
        <div className="border-t border-border px-6 py-4">
          <WarningAlert title={t('Unable to load estimation')}>
            <div className="space-y-3">
              <p>{message}</p>
              {error && onRetry ? (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 text-[13px]"
                  onClick={onRetry}
                >
                  {t('Try again')}
                </Button>
              ) : null}
            </div>
          </WarningAlert>
        </div>
      </div>
    )
  }

  // Models.Estimation / EstimationUpdatePlan: items + discounts; grossAmount is total due
  const estimationData = normalizeEstimationPayload(estimation)
  const currency = estimationData.currency ?? 'USD'
  const items = estimationData.items ?? []
  const lineItems = items.map((item) => ({
    name: getEstimationLineLabel(item),
    amount: getEstimationLineAmount(item),
    currency,
  }))

  const discountLines = getEstimationDiscountLines(estimationData)
  const totalDue =
    typeof estimationData.grossAmount === 'number'
      ? estimationData.grossAmount
      : 0
  const recurringCharge = estimationData.recurringCharge ?? 0

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden sticky top-6">
      <div className="px-6 py-4">
        <h3 className="text-[13px] font-semibold text-foreground">
          {t('Estimated total')}
        </h3>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        {/* Line Items */}
        {lineItems.length > 0 && (
          <div className="space-y-2">
            {lineItems.map((item, index) => (
              <div
                key={index}
                className="flex items-center justify-between text-[13px]"
              >
                <span className="text-muted-foreground">{t(item.name)}</span>
                <span className="font-medium text-foreground">
                  {formatCurrency(item.amount || 0, item.currency || 'USD')}
                </span>
              </div>
            ))}
          </div>
        )}

        {/* Discounts (includes credits and other reductions) */}
        {discountLines.length > 0 && (
          <div className="space-y-2 pt-2 border-t border-border">
            {discountLines.map((discountLine, index) => (
              <div
                key={index}
                className="flex items-center justify-between text-[13px]"
              >
                <span className="text-muted-foreground">{t(discountLine.label)}</span>
                <span className="font-medium text-green-600 dark:text-green-400">
                  -{formatCurrency(discountLine.amount, currency)}
                </span>
              </div>
            ))}
          </div>
        )}

        {/* Coupon Applied */}
        {coupon && (
          <div className="pt-2 border-t border-border">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0 space-y-1">
                <div className="flex items-center gap-2">
                  <Ticket className="h-4 w-4 shrink-0 text-muted-foreground" />
                  <span className="text-[13px] font-medium text-foreground">
                    {coupon.code}
                  </span>
                </div>
                <CouponExpirationNotice coupon={coupon} variant="compact" />
              </div>
              <Button
                variant="ghost"
                size="sm"
                className="h-6 w-6 shrink-0 p-0"
                onClick={onCouponRemove}
                aria-label={t('Remove coupon')}
              >
                <X className="h-3.5 w-3.5" />
              </Button>
            </div>
          </div>
        )}

        {/* Total Due */}
        <div className="pt-2 border-t border-border">
          <div className="flex items-center justify-between">
            <span className="text-[13px] font-medium text-foreground">
              {t('Total due now')}
            </span>
            <span className="text-[15px] font-semibold text-foreground">
              {formatCurrency(totalDue, currency)}
            </span>
          </div>
        </div>

        {/* Recurring Charge */}
        {recurringCharge > 0 && (
          <div className="pt-2">
            <div className="flex items-center justify-between">
              <span className="text-[13px] text-muted-foreground">
                {t('Recurring Charge')}
              </span>
              <span className="text-[13px] font-medium text-foreground">
                {formatCurrency(recurringCharge, currency)}
                /{t('month')}
              </span>
            </div>
          </div>
        )}

        {/* Budget Cap */}
        {estimationData.budgetEnabled !== false && (
          <div className="pt-4 border-t border-border space-y-3">
            <div className="flex items-center justify-between">
              <Label htmlFor="budget-cap" className="text-[13px] font-medium">
                {t('Budget cap')}
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
                  <span className="absolute start-3 top-1/2 -translate-y-1/2 text-[13px] text-muted-foreground">
                    $
                  </span>
                  <Input
                    id="budget-amount"
                    type="number"
                    placeholder="200"
                    value={budgetValue}
                    onChange={(e) => handleBudgetChange(e.target.value)}
                    className="h-9 text-[13px] ps-7"
                    min={1}
                    step={1}
                  />
                </div>
                <p className="text-[12px] text-muted-foreground mt-1">
                  {t('Set a monthly spending limit')}
                </p>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
