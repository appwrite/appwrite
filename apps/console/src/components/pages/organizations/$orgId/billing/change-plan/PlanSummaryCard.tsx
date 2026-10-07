import { useMemo } from 'react'
import type { BillingPlanTier } from '@/lib/constants/billing-plan'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Info } from '@/lib/icons'
import { formatCurrency } from '../utils'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { getBillingPlanDisplayLabel } from '@/lib/utils/plan-filter'
import { useT } from '@/lib/i18n/translate'

interface PlanSummaryCardProps {
  selectedPlan: BillingPlanTier | null
  billingPlans: Record<string, unknown>
  estimation: unknown | null
  isLoading: boolean
  budgetEnabled: boolean
  onBudgetToggle: (enabled: boolean) => void
}

export function PlanSummaryCard({
  selectedPlan,
  billingPlans,
  estimation,
  isLoading,
  budgetEnabled,
  onBudgetToggle,
}: PlanSummaryCardProps) {
  const t = useT()
  // Calculate billing cycle days (default to 30)
  const billingCycleDays = 30

  // Get plan price
  const planPrice = useMemo(() => {
    if (!selectedPlan || !billingPlans[selectedPlan]) return 0
    return billingPlans[selectedPlan].price || 0
  }, [selectedPlan, billingPlans])

  // Get additional charges from estimation
  const additionalCharges = useMemo(() => {
    if (!estimation || !estimation.lineItems) return []

    // Filter out the base plan charge and get additional charges
    return estimation.lineItems.filter((item: unknown) => {
      // Exclude the base plan charge
      const itemName = (item.name || item.description || '').toLowerCase()
      return !itemName.includes('plan') && item.amount > 0
    })
  }, [estimation])

  // Calculate total
  const totalDue = useMemo(() => {
    if (estimation && estimation.totalDue !== undefined) {
      return estimation.totalDue
    }
    // Fallback calculation
    const additionalTotal = additionalCharges.reduce(
      (sum: number, charge: unknown) => sum + (charge.amount || 0),
      0,
    )
    return planPrice + additionalTotal
  }, [estimation, planPrice, additionalCharges])

  if (isLoading) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            {t('Loading summary...')}
          </p>
        </div>
      </div>
    )
  }

  if (!selectedPlan) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            {t('Select a plan to see summary')}
          </p>
        </div>
      </div>
    )
  }

  const planName = getBillingPlanDisplayLabel(selectedPlan)
  const currency = estimation?.currency || 'USD'

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">{t('Summary')}</h3>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        {/* Plan Charge */}
        <div className="flex items-center justify-between text-[13px]">
          <span className="text-muted-foreground">{planName} {t('plan')}</span>
          <span className="font-medium text-foreground">
            {formatCurrency(planPrice, currency)}
          </span>
        </div>

        {/* Additional Charges */}
        {additionalCharges.length > 0 && (
          <div className="space-y-2 pt-2 border-t border-border">
            {additionalCharges.map((charge: unknown, index: number) => (
              <div
                key={index}
                className="flex items-center justify-between text-[13px]"
              >
                <span className="text-muted-foreground">
                  {charge.name || charge.description}
                </span>
                <span className="font-medium text-foreground">
                  {formatCurrency(charge.amount || 0, currency)}
                </span>
              </div>
            ))}
          </div>
        )}

        {/* Total Due */}
        <div className="pt-2 border-t border-border">
          <div className="flex items-center justify-between">
            <span className="text-[13px] font-semibold text-foreground">
              {t('Total due')}
            </span>
            <span className="text-[15px] font-semibold text-foreground">
              {formatCurrency(totalDue, currency)}
            </span>
          </div>
        </div>

        {/* Billing Statement */}
        <div className="pt-2">
          <p className="text-[13px] text-muted-foreground">
            {t("You'll pay")}{' '}
            <span className="font-semibold text-foreground">
              {formatCurrency(totalDue, currency)}
            </span>{' '}
            {t("now. Then you'll be charged")}{' '}
            <span className="font-semibold text-foreground">
              {formatCurrency(totalDue, currency)}
            </span>{' '}
            {t('every')} {billingCycleDays} {t('days')}.
          </p>
        </div>

        {/* Budget Cap Toggle */}
        <div className="pt-4 border-t border-border">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Label
                htmlFor="budget-cap"
                className="text-[13px] font-medium cursor-pointer"
              >
                {t('Enable budget cap')}
              </Label>
              <TooltipProvider>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      type="button"
                      className="text-muted-foreground hover:text-foreground"
                    >
                      <Info className="h-3.5 w-3.5" />
                    </button>
                  </TooltipTrigger>
                  <TooltipContent className="max-w-xs">
                    <p className="text-[12px]">
                      {t('Enable budget cap to prevent unexpected charges from additional usage beyond your plan limits.')}
                    </p>
                  </TooltipContent>
                </Tooltip>
              </TooltipProvider>
            </div>
            <Switch
              id="budget-cap"
              checked={budgetEnabled}
              onCheckedChange={(checked) => {
                onBudgetToggle(checked)
                // Note: Budget cap is managed separately in billing settings
                // This toggle is for display purposes only during plan change
              }}
            />
          </div>
        </div>
      </div>
    </div>
  )
}
