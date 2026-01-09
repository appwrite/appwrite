import { useState } from 'react'
import { Bell, Plus, Trash2 } from 'lucide-react'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { billingAlerts, type BillingAlert } from '@/lib/utils/mock-data'
import { cn } from '@/lib/utils'

/**
 * BillingAlertsSection Component
 *
 * Manages billing alert thresholds:
 * - List of configured alerts with toggle switches
 * - Add new alert with threshold dropdown
 * - Remove existing alerts
 *
 * Props: None
 *
 * State:
 * - alerts: BillingAlert[] - Current alert configurations
 * - showAddAlert: boolean - Controls add alert dropdown visibility
 *
 * Behavior:
 * - Alerts notify when spending reaches percentage of budget
 * - Multiple thresholds can be configured
 */

const AVAILABLE_THRESHOLDS = [25, 50, 75, 90, 100]

export function BillingAlertsSection() {
  const [alerts, setAlerts] = useState<BillingAlert[]>(billingAlerts)
  const [showAddAlert, setShowAddAlert] = useState(false)
  const [newThreshold, setNewThreshold] = useState<string>('')

  const usedThresholds = alerts.map((a) => a.threshold)
  const availableThresholds = AVAILABLE_THRESHOLDS.filter(
    (t) => !usedThresholds.includes(t),
  )

  const handleToggleAlert = (alertId: string, enabled: boolean) => {
    setAlerts((prev) =>
      prev.map((alert) =>
        alert.$id === alertId ? { ...alert, enabled } : alert,
      ),
    )
  }

  const handleAddAlert = () => {
    if (!newThreshold) return

    const threshold = parseInt(newThreshold, 10)
    const newAlert: BillingAlert = {
      $id: `alert_${Date.now()}`,
      threshold,
      enabled: true,
    }

    setAlerts((prev) =>
      [...prev, newAlert].sort((a, b) => a.threshold - b.threshold),
    )
    setNewThreshold('')
    setShowAddAlert(false)
  }

  const handleRemoveAlert = (alertId: string) => {
    setAlerts((prev) => prev.filter((alert) => alert.$id !== alertId))
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
          {alerts.map((alert) => (
            <div
              key={alert.$id}
              className="flex items-center justify-between px-6 py-3 hover:bg-accent/50 transition-colors"
            >
              <div className="flex items-center gap-4">
                <div
                  className={cn(
                    'flex h-9 w-9 items-center justify-center rounded-lg',
                    alert.enabled ? 'bg-primary/10' : 'bg-muted',
                  )}
                >
                  <Bell
                    className={cn(
                      'h-4 w-4',
                      alert.enabled ? 'text-primary' : 'text-muted-foreground',
                    )}
                  />
                </div>
                <div>
                  <p className="text-[13px] font-medium text-foreground">
                    {alert.threshold}% threshold
                  </p>
                  <p className="text-[12px] text-muted-foreground">
                    Alert when spending reaches {alert.threshold}% of budget
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-3">
                <Switch
                  checked={alert.enabled}
                  onCheckedChange={(checked) =>
                    handleToggleAlert(alert.$id, checked)
                  }
                />
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8 w-8 p-0 text-muted-foreground hover:text-red-600 dark:hover:text-red-400"
                  onClick={() => handleRemoveAlert(alert.$id)}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
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
              disabled={!newThreshold}
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
