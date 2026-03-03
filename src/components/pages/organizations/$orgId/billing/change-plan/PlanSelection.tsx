import { BillingPlan } from '@appwrite.io/console'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Label } from '@/components/ui/label'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Info, ExternalLink } from '@/lib/icons'
import { getPlanNameFromTier } from '@/lib/utils/plan-filter'
import { cn } from '@/lib/utils'

interface PlanSelectionProps {
  plans: Record<string, unknown>
  currentPlan: BillingPlan | string
  selectedPlan: BillingPlan | null
  onPlanSelect: (plan: BillingPlan) => void
  selfService: boolean
  hasFreeOrgs: boolean
  variant?: 'card' | 'inline'
}

export function PlanSelection({
  plans,
  currentPlan,
  selectedPlan,
  onPlanSelect,
  selfService,
  hasFreeOrgs,
  variant = 'card',
}: PlanSelectionProps) {
  const availablePlans =
    plans && typeof plans === 'object' ? Object.entries(plans) : []

  const getPlanDisplayName = (planTier: string) => {
    const planName = getPlanNameFromTier(planTier)
    if (planName === 'free') return 'Free'
    if (planName === 'pro') return 'Pro'
    return 'Custom'
  }

  const isCurrentPlan = (planTier: string) => {
    return (
      planTier === currentPlan ||
      getPlanNameFromTier(planTier) ===
        getPlanNameFromTier(currentPlan as string)
    )
  }

  const isFreePlan = (planTier: string) => {
    return getPlanNameFromTier(planTier) === 'free'
  }

  const isDisabled = (planTier: string) => {
    if (!selfService) return true
    if (isFreePlan(planTier) && hasFreeOrgs) return true
    return false
  }

  const radioGroupContent = (
    <>
      {/* Self-service restriction alert */}
      {!selfService && (
        <Alert className="mb-4">
          <Info className="h-4 w-4" />
          <AlertTitle>Plan Changes Restricted</AlertTitle>
          <AlertDescription className="mt-2">
            Plan changes are not available for self-service. Please contact
            support to change your plan.
          </AlertDescription>
        </Alert>
      )}

      <RadioGroup
        value={selectedPlan || undefined}
        onValueChange={(value) => onPlanSelect(value as BillingPlan)}
        className="space-y-2"
      >
        {availablePlans.map(([planTier, planData]) => {
          // Use plan name from API response, fallback to derived name
          const planName = planData?.name || getPlanDisplayName(planTier)
          const disabled = isDisabled(planTier)
          const isCurrent = isCurrentPlan(planTier)
          const price = planData?.price || 0
          // API uses 'desc' not 'description'
          const description = planData?.desc || planData?.description
          const isSelected = selectedPlan === planTier
          const showTooltip = disabled && isFreePlan(planTier) && hasFreeOrgs

          const planCardContent = (
            <>
              <RadioGroupItem
                value={planTier}
                id={planTier}
                disabled={disabled}
                className="mt-0.5 shrink-0 pointer-events-none"
              />
              <Label
                htmlFor={planTier}
                className={cn(
                  'flex-1 cursor-pointer min-w-0 pointer-events-none',
                  disabled && 'cursor-not-allowed',
                )}
              >
                <div className="space-y-0.5">
                  {/* Plan Name - on its own line */}
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-[15px] font-semibold text-foreground">
                      {planName}
                    </span>
                    {isCurrent && (
                      <Badge
                        variant="secondary"
                        className="text-[11px] font-medium px-2 py-0.5 h-5 shrink-0"
                      >
                        Current plan
                      </Badge>
                    )}
                  </div>

                  {/* Description - on its own line */}
                  {description && (
                    <p className="text-[13px] text-muted-foreground leading-snug">
                      {description}
                    </p>
                  )}

                  {/* Price - on its own line */}
                  <div className="text-[13px] font-medium text-foreground">
                    {price > 0 ? (
                      <span>${price.toFixed(2)} per month + usage</span>
                    ) : (
                      <span>$0.00</span>
                    )}
                  </div>
                </div>
              </Label>
            </>
          )

          const planCard = (
            <div
              key={planTier}
              className={cn(
                'flex items-start gap-3 rounded-lg border p-3 transition-colors',
                isSelected
                  ? 'border-primary bg-card'
                  : 'border-border bg-card/50 hover:border-primary/30',
                disabled && 'opacity-50 cursor-not-allowed',
              )}
            >
              {planCardContent}
            </div>
          )

          if (showTooltip) {
            return (
              <Tooltip key={planTier}>
                <TooltipTrigger asChild>
                  <div className="w-full">{planCard}</div>
                </TooltipTrigger>
                <TooltipContent>
                  <p>
                    You already have a free organization. You can only have one
                    free organization per account.
                  </p>
                </TooltipContent>
              </Tooltip>
            )
          }

          return planCard
        })}
      </RadioGroup>

      {variant === 'card' && (
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
      )}
    </>
  )

  if (variant === 'inline') {
    return <div>{radioGroupContent}</div>
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

      <div className="px-6 py-4">{radioGroupContent}</div>
    </div>
  )
}
