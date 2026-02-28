import { BillingPlan } from '@appwrite.io/console'
import { getPlanNameFromTier } from '@/lib/utils/plan-filter'

interface PlanComparisonBoxProps {
  currentPlan: BillingPlan | string
  selectedPlan: BillingPlan | null
  plans: Record<string, unknown>
}

export function PlanComparisonBox({
  currentPlan,
  selectedPlan,
}: PlanComparisonBoxProps) {
  const getPlanDisplayName = (planTier: string) => {
    const planName = getPlanNameFromTier(planTier)
    if (planName === 'free') return 'Free'
    if (planName === 'pro') return 'Pro'
    return 'Custom'
  }

  const currentPlanName = getPlanDisplayName(currentPlan as string)
  const selectedPlanName = selectedPlan
    ? getPlanDisplayName(selectedPlan)
    : null

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden sticky top-6">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Plan Comparison
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Compare features between plans
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4">
        <div className="space-y-4">
          <div className="flex items-center justify-between text-[13px]">
            <span className="text-muted-foreground">Current Plan</span>
            <span className="font-medium text-foreground">
              {currentPlanName}
            </span>
          </div>
          {selectedPlanName && (
            <div className="flex items-center justify-between text-[13px]">
              <span className="text-muted-foreground">Selected Plan</span>
              <span className="font-medium text-foreground">
                {selectedPlanName}
              </span>
            </div>
          )}
          {!selectedPlan && (
            <p className="text-[12px] text-muted-foreground">
              Select a plan to see comparison
            </p>
          )}
        </div>
      </div>
    </div>
  )
}
