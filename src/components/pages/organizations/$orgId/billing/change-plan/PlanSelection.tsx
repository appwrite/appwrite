import { BillingPlan } from '@appwrite.io/console'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Label } from '@/components/ui/label'
import { Info, AlertTriangle, ExternalLink } from 'lucide-react'
import { getPlanNameFromTier } from '@/lib/utils/plan-filter'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'

interface PlanSelectionProps {
  plans: Record<string, any>
  currentPlan: BillingPlan | string
  selectedPlan: BillingPlan | null
  onPlanSelect: (plan: BillingPlan) => void
  selfService: boolean
  hasFreeOrgs: boolean
}

export function PlanSelection({
  plans,
  currentPlan,
  selectedPlan,
  onPlanSelect,
  selfService,
  hasFreeOrgs,
}: PlanSelectionProps) {
  // Filter out Scale plan (not shown in UI per instructions)
  // Handle empty plans object gracefully
  const availablePlans = plans && typeof plans === 'object' 
    ? Object.entries(plans).filter(([key]) => {
        const planName = getPlanNameFromTier(key)
        return planName !== 'scale'
      })
    : []

  const getPlanDisplayName = (planTier: string) => {
    const planName = getPlanNameFromTier(planTier)
    if (planName === 'free') return 'Free'
    if (planName === 'pro') return 'Pro'
    if (planName === 'scale') return 'Scale'
    return 'Custom'
  }

  const isCurrentPlan = (planTier: string) => {
    return planTier === currentPlan || getPlanNameFromTier(planTier) === getPlanNameFromTier(currentPlan as string)
  }

  const isFreePlan = (planTier: string) => {
    return getPlanNameFromTier(planTier) === 'free'
  }

  const isDisabled = (planTier: string) => {
    if (!selfService) return true
    if (isFreePlan(planTier) && hasFreeOrgs) return true
    return false
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Select a Plan
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Choose the plan that best fits your needs.
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4">
        {/* Self-service restriction alert */}
        {!selfService && (
          <Alert className="mb-4">
            <Info className="h-4 w-4" />
            <AlertTitle>Plan Changes Restricted</AlertTitle>
            <AlertDescription className="mt-2">
              Plan changes are not available for self-service. Please contact support to change your plan.
            </AlertDescription>
          </Alert>
        )}

        {/* Free org restriction alert */}
        {hasFreeOrgs && (
          <Alert variant="destructive" className="mb-4">
            <AlertTriangle className="h-4 w-4" />
            <AlertTitle>Free Organization Limit</AlertTitle>
            <AlertDescription className="mt-2">
              You already have a free organization. You can only have one free organization per account.
            </AlertDescription>
          </Alert>
        )}

        <RadioGroup
          value={selectedPlan || undefined}
          onValueChange={(value) => onPlanSelect(value as BillingPlan)}
          className="space-y-3"
        >
          {availablePlans.map(([planTier, planData]) => {
            const planName = getPlanDisplayName(planTier)
            const disabled = isDisabled(planTier)
            const isCurrent = isCurrentPlan(planTier)
            const price = planData?.price || 0

            return (
              <div
                key={planTier}
                className={cn(
                  'flex items-start space-x-3 rounded-lg border p-4 transition-colors',
                  selectedPlan === planTier
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:bg-muted/50',
                  disabled && 'opacity-50 cursor-not-allowed',
                )}
              >
                <RadioGroupItem
                  value={planTier}
                  id={planTier}
                  disabled={disabled}
                  className="mt-1"
                />
                <Label
                  htmlFor={planTier}
                  className={cn(
                    'flex-1 cursor-pointer',
                    disabled && 'cursor-not-allowed',
                  )}
                >
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <span className="text-[15px] font-semibold text-foreground">
                        {planName}
                      </span>
                      {isCurrent && (
                        <Badge variant="secondary" className="text-[11px]">
                          Current
                        </Badge>
                      )}
                    </div>
                    <div className="text-right">
                      {price > 0 ? (
                        <span className="text-[15px] font-semibold text-foreground">
                          ${price}/month
                        </span>
                      ) : (
                        <span className="text-[15px] font-semibold text-foreground">
                          Free
                        </span>
                      )}
                    </div>
                  </div>
                  {planData?.description && (
                    <p className="text-[13px] text-muted-foreground mt-1">
                      {planData.description}
                    </p>
                  )}
                  {disabled && isFreePlan(planTier) && hasFreeOrgs && (
                    <p className="text-[12px] text-muted-foreground mt-1">
                      You already have a free organization
                    </p>
                  )}
                </Label>
              </div>
            )
          })}
        </RadioGroup>

        <div className="mt-4">
          <Button
            variant="ghost"
            size="sm"
            className="text-[13px] text-muted-foreground"
            asChild
          >
            <a
              href="https://appwrite.io/pricing"
              target="_blank"
              rel="noopener noreferrer"
            >
              View detailed pricing
              <ExternalLink className="ml-1.5 h-3.5 w-3.5" />
            </a>
          </Button>
        </div>
      </div>
    </div>
  )
}
