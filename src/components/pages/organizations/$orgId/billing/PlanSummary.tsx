import { useState } from 'react'
import { ChevronDown, ChevronUp, Folder } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { planDetails } from '@/lib/utils/mock-data'
import { formatCurrency, formatDate, getRelativeTime } from './utils'
import { cn } from '@/lib/utils'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'

/**
 * PlanSummary Component
 *
 * Displays the current plan information including:
 * - Plan name and next payment date
 * - Billing cycle date range
 * - Expandable charges breakdown with:
 *   - Base plan cost
 *   - Org-level addons (additional projects, members)
 *   - Collapsible project breakdowns with project-specific addons
 * - Total projected amount
 * - Change plan action
 *
 * Props:
 * - onChangePlan?: () => void - Callback when user clicks change plan
 *
 * State:
 * - expanded: boolean - Controls visibility of charges breakdown
 * - expandedProjects: Set<string> - Tracks which project breakdowns are expanded
 */

interface PlanSummaryProps {
  onChangePlan?: () => void
}

export function PlanSummary({ onChangePlan }: PlanSummaryProps) {
  const [expanded, setExpanded] = useState(false)
  const [expandedProjects, setExpandedProjects] = useState<Set<string>>(
    new Set(),
  )

  // Calculate totals
  const orgAddonsTotal = planDetails.orgAddons.reduce(
    (sum, addon) => sum + addon.total,
    0,
  )
  const projectAddonsTotal = planDetails.projectBreakdowns.reduce(
    (sum, project) => sum + project.total,
    0,
  )
  const totalAmount =
    planDetails.basePrice + orgAddonsTotal + projectAddonsTotal

  // Toggle project expansion
  const toggleProject = (projectId: string) => {
    setExpandedProjects((prev) => {
      const next = new Set(prev)
      if (next.has(projectId)) {
        next.delete(projectId)
      } else {
        next.add(projectId)
      }
      return next
    })
  }

  // Filter projects with charges
  const projectsWithCharges = planDetails.projectBreakdowns.filter(
    (p) => p.total > 0,
  )

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      {/* Header */}
      <div className="px-6 py-4">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h3 className="text-[15px] font-semibold text-foreground">
                {planDetails.name} Plan
              </h3>
              <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                {planDetails.billingCycle}
              </span>
            </div>
            <p className="text-[12px] text-muted-foreground">
              Next payment {getRelativeTime(planDetails.nextPaymentDate)}
            </p>
          </div>
          <div className="text-right shrink-0">
            <p className="text-[20px] font-semibold text-foreground">
              {formatCurrency(totalAmount)}
            </p>
            <p className="text-[11px] text-muted-foreground">
              projected renewal
            </p>
          </div>
        </div>
      </div>

      {/* Billing Cycle */}
      <div className="border-t border-border px-6 py-3 bg-muted/30">
        <div className="flex items-center justify-between text-[12px]">
          <span className="text-muted-foreground">Current billing cycle</span>
          <span className="font-medium text-foreground">
            {formatDate(planDetails.cycleStart)} –{' '}
            {formatDate(planDetails.cycleEnd)}
          </span>
        </div>
      </div>

      {/* Expandable Charges Breakdown */}
      <div className="border-t border-border">
        <button
          onClick={() => setExpanded(!expanded)}
          className="flex w-full items-center justify-between px-6 py-3 text-[13px] text-muted-foreground hover:bg-accent/50 transition-colors"
        >
          <span>View charges breakdown</span>
          {expanded ? (
            <ChevronUp className="h-4 w-4" />
          ) : (
            <ChevronDown className="h-4 w-4" />
          )}
        </button>

        <div
          className={cn(
            'overflow-hidden transition-all duration-200',
            expanded ? 'max-h-[800px]' : 'max-h-0',
          )}
        >
          <div className="border-t border-border px-6 py-4 space-y-4">
            {/* Base Plan */}
            <div className="flex items-center justify-between text-[13px]">
              <span className="text-foreground">
                {planDetails.name} plan (base)
              </span>
              <span className="font-medium text-foreground">
                {formatCurrency(planDetails.basePrice)}
              </span>
            </div>

            {/* Org-level Addons Section */}
            {planDetails.orgAddons.length > 0 && (
              <div className="space-y-2">
                <div className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                  Organization Add-ons
                </div>
                {planDetails.orgAddons.map((addon, index) => (
                  <div
                    key={index}
                    className="flex items-center justify-between text-[13px]"
                  >
                    <span className="text-muted-foreground">
                      {addon.name}
                      <span className="ml-1 text-[11px]">
                        ({addon.quantity} × {formatCurrency(addon.unitPrice)})
                      </span>
                    </span>
                    <span className="text-foreground">
                      {formatCurrency(addon.total)}
                    </span>
                  </div>
                ))}
              </div>
            )}

            {/* Project Breakdown Section */}
            {projectsWithCharges.length > 0 && (
              <div className="space-y-2">
                <div className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                  Project Breakdown
                </div>
                <div className="space-y-1">
                  {projectsWithCharges.map((project) => (
                    <Collapsible
                      key={project.projectId}
                      open={expandedProjects.has(project.projectId)}
                      onOpenChange={() => toggleProject(project.projectId)}
                    >
                      <CollapsibleTrigger asChild>
                        <button className="flex w-full items-center justify-between rounded-md px-2 py-2 text-[13px] hover:bg-accent/50 transition-colors -mx-2">
                          <span className="flex items-center gap-2 text-foreground">
                            <Folder className="h-3.5 w-3.5 text-muted-foreground" />
                            {project.projectName}
                          </span>
                          <span className="flex items-center gap-2">
                            <span className="font-medium text-foreground">
                              {formatCurrency(project.total)}
                            </span>
                            {expandedProjects.has(project.projectId) ? (
                              <ChevronUp className="h-3.5 w-3.5 text-muted-foreground" />
                            ) : (
                              <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" />
                            )}
                          </span>
                        </button>
                      </CollapsibleTrigger>
                      <CollapsibleContent>
                        <div className="ml-5 mt-1 space-y-1.5 border-l border-border pl-3 pb-2">
                          {project.addons.map((addon, addonIndex) => (
                            <div
                              key={addonIndex}
                              className="flex items-center justify-between text-[12px]"
                            >
                              <span className="text-muted-foreground">
                                {addon.name}
                                <span className="ml-1 text-[10px] opacity-70">
                                  ({addon.usage})
                                </span>
                              </span>
                              <span className="text-foreground">
                                {formatCurrency(addon.total)}
                              </span>
                            </div>
                          ))}
                        </div>
                      </CollapsibleContent>
                    </Collapsible>
                  ))}
                </div>
              </div>
            )}

            {/* Total */}
            <div className="flex items-center justify-between border-t border-border pt-3 text-[13px]">
              <span className="font-medium text-foreground">Total</span>
              <span className="font-semibold text-foreground">
                {formatCurrency(totalAmount)}
              </span>
            </div>
          </div>
        </div>
      </div>

      {/* Footer */}
      <div className="border-t border-border px-6 py-4 bg-muted/30">
        <Button
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          onClick={onChangePlan}
        >
          Change plan
        </Button>
      </div>
    </div>
  )
}
