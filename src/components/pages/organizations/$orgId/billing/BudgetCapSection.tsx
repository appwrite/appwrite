import { useState, useEffect } from 'react'
import { Gauge, Info, AlertCircle } from 'lucide-react'
import { Switch } from '@/components/ui/switch'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { formatCurrency } from './utils'
import {
  useOrganizationById,
  useOrganizationPlan,
  useUpdateOrganizationBudget,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'

/**
 * BudgetCapSection Component
 *
 * Manages budget cap settings:
 * - Toggle to enable/disable budget cap
 * - Budget limit input when enabled
 * - Explanatory text about how it works
 *
 * Props:
 * - orgId?: string - Organization ID
 *
 * State:
 * - enabled: boolean - Whether budget cap is active
 * - limit: string - Budget limit amount (as string for input)
 */

interface BudgetCapSectionProps {
  orgId?: string
}

export function BudgetCapSection({ orgId }: BudgetCapSectionProps) {
  const { organization, isLoading: orgLoading } = useOrganizationById(orgId)
  const { plan, isLoading: planLoading } = useOrganizationPlan(orgId)
  const updateBudgetMutation = useUpdateOrganizationBudget()

  const [budget, setBudget] = useState<string>('')
  const [hasChanges, setHasChanges] = useState(false)

  // Initialize from organization
  useEffect(() => {
    if (organization?.billingBudget !== undefined) {
      setBudget(
        organization.billingBudget > 0
          ? organization.billingBudget.toString()
          : '',
      )
      setHasChanges(false)
    }
  }, [organization?.billingBudget])

  const enabled = (organization?.billingBudget || 0) > 0
  const isLoading =
    (orgLoading && !organization) || (planLoading && !plan)

  // Check if plan supports budgeting
  const supportsBudgeting = plan?.budgeting !== false

  const handleToggle = async (checked: boolean) => {
    if (!orgId) return

    const newBudget = checked ? (budget ? parseFloat(budget) : 100) : 0

    try {
      await updateBudgetMutation.mutateAsync({
        organizationId: orgId,
        budget: newBudget,
        alerts: organization?.budgetAlerts || [],
      })
      setBudget(newBudget > 0 ? newBudget.toString() : '')
      setHasChanges(false)
      toast.success(checked ? 'Budget cap enabled' : 'Budget cap disabled')
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'Failed to update budget cap',
      )
    }
  }

  const handleLimitChange = (value: string) => {
    // Only allow numbers and decimal point
    if (/^\d*\.?\d*$/.test(value)) {
      setBudget(value)
      const currentBudget = organization?.billingBudget || 0
      const newBudget = value ? parseFloat(value) : 0
      setHasChanges(newBudget !== currentBudget)
    }
  }

  const handleSave = async () => {
    if (!orgId) return

    const budgetValue = budget ? parseFloat(budget) : 0

    if (budgetValue <= 0) {
      toast.error('Budget cap must be greater than 0')
      return
    }

    try {
      await updateBudgetMutation.mutateAsync({
        organizationId: orgId,
        budget: budgetValue,
        alerts: organization?.budgetAlerts || [],
      })
      toast.success('Budget cap updated')
      setHasChanges(false)
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'Failed to update budget cap',
      )
    }
  }

  if (isLoading) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Budget cap
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">Loading...</p>
        </div>
      </div>
    )
  }

  if (!supportsBudgeting) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Budget cap
          </h3>
        </div>
        <div className="border-t border-border px-6 py-4">
          <Alert>
            <AlertCircle className="h-4 w-4" />
            <AlertDescription className="text-[13px]">
              Budget caps are not supported on your current plan.
            </AlertDescription>
          </Alert>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      {/* Header */}
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Budget cap
        </h3>
      </div>

      {/* Content */}
      <div className="border-t border-border px-6 py-4">
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-start gap-4">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted shrink-0">
              <Gauge className="h-5 w-5 text-muted-foreground" />
            </div>
            <div>
              <p className="text-[13px] font-medium text-foreground">
                Enable budget cap
              </p>
              <p className="text-[12px] text-muted-foreground mt-0.5">
                Budget cap applies only to additional usage beyond your plan
                limits
              </p>
            </div>
          </div>
          <Switch
            checked={enabled}
            onCheckedChange={handleToggle}
            disabled={updateBudgetMutation.isPending}
          />
        </div>

        {/* Budget Limit Input */}
        {enabled && (
          <div className="mt-4 ps-14">
            <label className="text-[12px] text-muted-foreground">
              Budget cap (USD)
            </label>
            <div className="mt-1.5 flex items-center gap-2">
              <div className="relative">
                <span className="absolute start-3 top-1/2 -translate-y-1/2 text-[13px] text-muted-foreground">
                  $
                </span>
                <Input
                  value={budget}
                  onChange={(e) => handleLimitChange(e.target.value)}
                  className="h-9 w-32 ps-7 text-[13px]"
                  placeholder="0.00"
                />
              </div>
            </div>
          </div>
        )}

        {/* Info Box */}
        <div className="mt-4 flex items-start gap-2 rounded-lg bg-muted/50 p-3">
          <Info className="h-4 w-4 text-muted-foreground shrink-0 mt-0.5" />
          <p className="text-[12px] text-muted-foreground">
            {enabled
              ? `When your additional usage spending (beyond plan limits) reaches ${formatCurrency(parseFloat(budget) || 0)}, all billable services will be paused until the next billing cycle or until you increase your limit.`
              : 'Enable budget cap to prevent unexpected charges from additional usage beyond your plan limits. Your services will automatically pause when the spending limit is reached.'}
          </p>
        </div>
      </div>

      {/* Footer */}
      {enabled && (
        <div className="border-t border-border px-6 py-4 bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            onClick={handleSave}
            disabled={!hasChanges || updateBudgetMutation.isPending}
          >
            Update
          </Button>
        </div>
      )}
    </div>
  )
}
