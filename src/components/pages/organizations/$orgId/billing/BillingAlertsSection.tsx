import { useState } from 'react'
import { Bell, Plus, Trash2, AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription } from '@/components/ui/alert'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  useOrganizationById,
  useUpdateOrganizationBudget,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'

/**
 * BillingAlertsSection Component
 *
 * Manages billing alert thresholds:
 * - List of configured alerts with toggle switches
 * - Add new alert with threshold dropdown
 * - Remove existing alerts
 *
 * Props:
 * - orgId?: string - Organization ID
 *
 * State:
 * - alerts: number[] - Current alert threshold percentages
 */

const AVAILABLE_THRESHOLDS = [25, 50, 75, 90, 100]

interface BillingAlertsSectionProps {
  orgId?: string
}

export function BillingAlertsSection({ orgId }: BillingAlertsSectionProps) {
  const { organization, isLoading: orgLoading } = useOrganizationById(orgId)
  const updateBudgetMutation = useUpdateOrganizationBudget()
  const [showAddAlert, setShowAddAlert] = useState(false)
  const [newThreshold, setNewThreshold] = useState<string>('')

  // Get current alerts from organization
  const alerts = organization?.budgetAlerts || []
  const budget = organization?.billingBudget || 0
  const enabled = budget > 0

  const usedThresholds = alerts
  const availableThresholds = AVAILABLE_THRESHOLDS.filter(
    (t) => !usedThresholds.includes(t),
  )

  const handleAddAlert = async () => {
    if (!newThreshold || !orgId) return

    const threshold = parseInt(newThreshold, 10)
    const newAlerts = [...alerts, threshold].sort((a, b) => a - b)

    try {
      await updateBudgetMutation.mutateAsync({
        organizationId: orgId,
        budget,
        alerts: newAlerts,
      })
      toast.success('Alert added')
      setNewThreshold('')
      setShowAddAlert(false)
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'Failed to add alert',
      )
    }
  }

  const handleRemoveAlert = async (threshold: number) => {
    if (!orgId) return

    const newAlerts = alerts.filter((t) => t !== threshold)

    try {
      await updateBudgetMutation.mutateAsync({
        organizationId: orgId,
        budget,
        alerts: newAlerts,
      })
      toast.success('Alert removed')
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'Failed to remove alert',
      )
    }
  }

  if (orgLoading) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Billing Alerts
          </h3>
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">Loading...</p>
        </div>
      </div>
    )
  }

  if (!enabled) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Billing Alerts
          </h3>
        </div>
        <div className="border-t border-border px-6 py-4">
          <Alert>
            <AlertCircle className="h-4 w-4" />
            <AlertDescription className="text-[13px]">
              Enable budget cap to configure billing alerts.
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
          Billing Alerts
        </h3>
      </div>

      {/* Description */}
      <div className="border-t border-border px-6 py-4">
        <p className="text-[13px] text-muted-foreground">
          Get notified when your spending reaches certain thresholds of your
          budget.
        </p>
      </div>

      {/* Alerts List */}
      {alerts.length > 0 && (
        <div className="border-t border-border divide-y divide-border">
          {alerts.map((threshold) => (
            <div
              key={threshold}
              className="flex items-center justify-between px-6 py-3 hover:bg-accent/50 transition-colors"
            >
              <div className="flex items-center gap-4">
                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10">
                  <Bell className="h-4 w-4 text-primary" />
                </div>
                <div>
                  <p className="text-[13px] font-medium text-foreground">
                    {threshold}% threshold
                  </p>
                  <p className="text-[12px] text-muted-foreground">
                    Alert when spending reaches {threshold}% of budget
                  </p>
                </div>
              </div>
              <Button
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0 text-muted-foreground hover:text-red-600 dark:hover:text-red-400"
                onClick={() => handleRemoveAlert(threshold)}
                disabled={updateBudgetMutation.isPending}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </div>
          ))}
        </div>
      )}

      {/* Empty State */}
      {alerts.length === 0 && (
        <div className="border-t border-border px-6 py-8 text-center">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
            <Bell className="h-6 w-6 text-muted-foreground" />
          </div>
          <p className="text-[13px] text-muted-foreground">
            No billing alerts configured
          </p>
        </div>
      )}

      {/* Add Alert */}
      {showAddAlert && availableThresholds.length > 0 && (
        <div className="border-t border-border px-6 py-4 bg-muted/30">
          <div className="flex items-center gap-3">
            <Select value={newThreshold} onValueChange={setNewThreshold}>
              <SelectTrigger className="w-40 h-9 text-[13px]">
                <SelectValue placeholder="Select threshold" />
              </SelectTrigger>
              <SelectContent>
                {availableThresholds.map((threshold) => (
                  <SelectItem
                    key={threshold}
                    value={threshold.toString()}
                    className="text-[13px]"
                  >
                    {threshold}%
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleAddAlert}
              disabled={!newThreshold || updateBudgetMutation.isPending}
            >
              Add alert
            </Button>
            <Button
              variant="ghost"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => {
                setShowAddAlert(false)
                setNewThreshold('')
              }}
            >
              Cancel
            </Button>
          </div>
        </div>
      )}

      {/* Footer */}
      {!showAddAlert && availableThresholds.length > 0 && (
        <div className="border-t border-border px-6 py-4 bg-muted/30">
          <Button
            variant="outline"
            size="sm"
            className="h-9 gap-2 text-[13px]"
            onClick={() => setShowAddAlert(true)}
          >
            <Plus className="h-4 w-4" />
            Add alert
          </Button>
        </div>
      )}
    </div>
  )
}
