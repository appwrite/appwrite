import { useState } from 'react'
import { Gauge, Info } from 'lucide-react'
import { Switch } from '@/components/ui/switch'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { budgetCap } from '@/lib/utils/mock-data'
import { formatCurrency } from './utils'

/**
 * BudgetCapSection Component
 *
 * Manages budget cap settings:
 * - Toggle to enable/disable budget cap
 * - Budget limit input when enabled
 * - Explanatory text about how it works
 *
 * Props: None
 *
 * State:
 * - enabled: boolean - Whether budget cap is active
 * - limit: number - Budget limit amount
 *
 * Behavior:
 * - When enabled, services pause when limit is reached
 * - Shows current limit and allows updating
 */

export function BudgetCapSection() {
  const [enabled, setEnabled] = useState(budgetCap.enabled)
  const [limit, setLimit] = useState(budgetCap.limit.toString())
  const [hasChanges, setHasChanges] = useState(false)

  const handleToggle = (checked: boolean) => {
    setEnabled(checked)
    setHasChanges(true)
  }

  const handleLimitChange = (value: string) => {
    // Only allow numbers and decimal point
    if (/^\d*\.?\d*$/.test(value)) {
      setLimit(value)
      setHasChanges(true)
    }
  }

  const handleSave = () => {
    // Save logic would go here
    setHasChanges(false)
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      {/* Header */}
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Budget Cap
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
                Automatically pause services when spending reaches your limit
              </p>
            </div>
          </div>
          <Switch checked={enabled} onCheckedChange={handleToggle} />
        </div>

        {/* Budget Limit Input */}
        {enabled && (
          <div className="mt-4 pl-14">
            <label className="text-[12px] text-muted-foreground">
              Monthly spending limit
            </label>
            <div className="mt-1.5 flex items-center gap-2">
              <div className="relative">
                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-[13px] text-muted-foreground">
                  $
                </span>
                <Input
                  value={limit}
                  onChange={(e) => handleLimitChange(e.target.value)}
                  className="h-9 w-32 pl-7 text-[13px]"
                  placeholder="0.00"
                />
              </div>
              <span className="text-[13px] text-muted-foreground">
                {budgetCap.currency}
              </span>
            </div>
          </div>
        )}

        {/* Info Box */}
        <div className="mt-4 flex items-start gap-2 rounded-lg bg-muted/50 p-3">
          <Info className="h-4 w-4 text-muted-foreground shrink-0 mt-0.5" />
          <p className="text-[12px] text-muted-foreground">
            {enabled
              ? `When your spending reaches ${formatCurrency(parseFloat(limit) || 0)}, all billable services will be paused until the next billing cycle or until you increase your limit.`
              : 'Enable budget cap to prevent unexpected charges. Your services will automatically pause when the spending limit is reached.'}
          </p>
        </div>
      </div>

      {/* Footer */}
      {hasChanges && (
        <div className="border-t border-border px-6 py-4 bg-muted/30">
          <Button size="sm" className="h-9 text-[13px]" onClick={handleSave}>
            Update
          </Button>
        </div>
      )}
    </div>
  )
}
