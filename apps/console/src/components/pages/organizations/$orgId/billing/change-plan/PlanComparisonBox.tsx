import type { BillingPlanTier } from '@/lib/constants/billing-plan'
import { getBillingPlanDisplayLabel } from '@/lib/utils/plan-filter'
import { useT } from '@/lib/i18n/translate'

interface PlanComparisonBoxProps {
  currentPlan: BillingPlanTier | string
  selectedPlan: BillingPlanTier | null
  plans: Record<string, unknown>
}

export function PlanComparisonBox({
  currentPlan,
  selectedPlan,
}: PlanComparisonBoxProps) {
  const t = useT()
  const currentPlanName = getBillingPlanDisplayLabel(currentPlan as string)
  const selectedPlanName = selectedPlan
    ? getBillingPlanDisplayLabel(selectedPlan)
    : null

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden sticky top-6">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Plan comparison')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Compare features between plans')}
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4">
        <div className="space-y-4">
          <div className="flex items-center justify-between text-[13px]">
            <span className="text-muted-foreground">{t('Current Plan')}</span>
            <span className="font-medium text-foreground">
              {currentPlanName}
            </span>
          </div>
          {selectedPlanName && (
            <div className="flex items-center justify-between text-[13px]">
              <span className="text-muted-foreground">{t('Selected Plan')}</span>
              <span className="font-medium text-foreground">
                {selectedPlanName}
              </span>
            </div>
          )}
          {!selectedPlan && (
            <p className="text-[12px] text-muted-foreground">
              {t('Select a plan to see comparison')}
            </p>
          )}
        </div>
      </div>
    </div>
  )
}
