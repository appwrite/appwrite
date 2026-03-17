import { useState, useMemo, useEffect } from 'react'
import { useSearch, useNavigate } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import {
  ChevronDown,
  ChevronUp,
  Folder,
  ExternalLink,
  ArrowUpCircle,
  ArrowLeftRight,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Progress } from '@/components/ui/progress'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
  TooltipProvider,
} from '@/components/ui/tooltip'
import { formatCurrency, formatDate } from './utils'
import { cn } from '@/lib/utils'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import {
  useOrganizationById,
  useOrganizationPlan,
  useOrganizationCredits,
  organizationBillingAggregationQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_BILLING_PROJECTS_LIMIT } from '@/lib/react-query/hooks/constants'
import { getPlanNameFromTier } from '@/lib/utils/plan-filter'
import { Link } from '@tanstack/react-router'
import { Pagination } from '@/components/global/shared/Pagination'

/**
 * PlanSummary Component
 *
 * Displays the current plan information matching the old console format:
 * - Plan name and next payment date
 * - Billing cycle date range
 * - Expandable charges breakdown with:
 *   - Base plan cost
 *   - Additional members cost
 *   - Additional projects cost with count badge
 *   - Project breakdowns with detailed usage metrics, progress bars, and costs
 * - Total projected amount
 * - Change plan action
 */

interface PlanSummaryProps {
  onChangePlan?: () => void
  orgId?: string
}

interface ResourceItem {
  name: string
  usage: number
  limit: number | null
  cost: number
  formatType: 'bytes' | 'number' | 'sms'
}

export function PlanSummary({ onChangePlan, orgId }: PlanSummaryProps) {
  const [expanded, setExpanded] = useState(true) // Default to expanded
  const [expandedProjects, setExpandedProjects] = useState<Set<string>>(
    new Set(),
  )

  // Pagination: requestedPage from URL (user intent); displayedPage = what we show (no layout shift until new data is ready)
  const search = useSearch({ strict: false })
  const navigate = useNavigate()
  const requestedPage = Number(search?.page) || 1
  // Fixed at 10; page size selector is hidden so limit from URL is ignored
  const pageLimit = DEFAULT_BILLING_PROJECTS_LIMIT
  const [displayedPage, setDisplayedPage] = useState(requestedPage)

  // Fetch organization data
  const { organization, isLoading: orgLoading } = useOrganizationById(orgId)
  const { plan, isLoading: planLoading } = useOrganizationPlan(orgId)

  // Requested page query (drives fetch when user changes page)
  const {
    isFetching: requestedAggregationFetching,
    isLoading: requestedAggregationLoading,
  } = useQuery(
    organizationBillingAggregationQueryOptions(
      orgId,
      organization?.billingAggregationId,
      pageLimit,
      (requestedPage - 1) * pageLimit,
    ),
  )

  // Displayed page query (what we show; stays on current page until requested page has loaded)
  const { data: aggregation, isLoading: aggLoading } = useQuery(
    organizationBillingAggregationQueryOptions(
      orgId,
      organization?.billingAggregationId,
      pageLimit,
      (displayedPage - 1) * pageLimit,
    ),
  )

  // Keep showing current page until the requested page has finished loading (no layout shift)
  useEffect(() => {
    if (
      requestedAggregationFetching ||
      requestedAggregationLoading ||
      requestedPage === displayedPage
    ) {
      return
    }
    setDisplayedPage(requestedPage)
  }, [
    requestedAggregationFetching,
    requestedAggregationLoading,
    requestedPage,
    displayedPage,
  ])

  const { credits } = useOrganizationCredits(orgId, 0, 1)

  // Calculate available credit
  const availableCredit = useMemo(() => {
    if (!credits || credits.length === 0) return 0
    const now = new Date()
    return credits.reduce((sum, credit) => {
      if (credit.expiresAt && new Date(credit.expiresAt) > now) {
        return sum + (credit.remaining || 0)
      }
      return sum
    }, 0)
  }, [credits])

  // Get plan name from plan object
  const planName = useMemo(() => {
    if (!plan || !plan.name) {
      if (!organization) return 'Free'
      return getPlanNameFromTier(organization.billingPlan)
    }
    return plan.name
  }, [plan, organization])

  // Get next plan if downgrade is scheduled
  const nextPlan = useMemo(() => {
    if (!organization?.billingPlanDowngrade) return null
    // TODO: Fetch next plan details if needed
    // For now, we'll use current plan price
    return null
  }, [organization])

  // Get base plan price (use next plan if downgrade scheduled)
  const basePlanPrice = useMemo(() => {
    if (nextPlan) {
      return nextPlan.price || 0
    }
    if (!plan) return 0
    return plan.price || 0
  }, [plan, nextPlan])

  // Get base amount (from aggregation if available, otherwise plan price)
  const baseAmount = useMemo(() => {
    if (
      aggregation &&
      aggregation.amount !== undefined &&
      aggregation.amount !== null
    ) {
      return aggregation.amount
    }
    return basePlanPrice
  }, [aggregation, basePlanPrice])

  // Calculate credits applied
  const creditsApplied = useMemo(() => {
    return Math.min(baseAmount, availableCredit)
  }, [baseAmount, availableCredit])

  // Calculate total amount
  const totalAmount = useMemo(() => {
    return Math.max(baseAmount - creditsApplied, 0)
  }, [baseAmount, creditsApplied])

  // Get billing cycle dates from organization
  const billingCycle = useMemo(() => {
    if (!organization) return null

    const cycleStart = organization.billingCurrentInvoiceDate
    const cycleEnd = organization.billingNextInvoiceDate

    if (cycleStart && cycleEnd) {
      return {
        start: cycleStart,
        end: cycleEnd,
      }
    }

    return null
  }, [organization])

  // Get next payment date (same as billing cycle end)
  const nextPaymentDate = useMemo(() => {
    if (billingCycle) {
      return billingCycle.end
    }
    return null
  }, [billingCycle])

  // Get billing cycle label
  const billingCycleLabel = useMemo(() => {
    if (!plan) return 'Monthly'
    return plan.billingCycle || 'Monthly'
  }, [plan])

  // Get additional members cost from aggregation
  const additionalMembersCost = useMemo(() => {
    if (!aggregation || !aggregation.additionalMemberAmount) return 0
    return aggregation.additionalMemberAmount
  }, [aggregation])

  const additionalMembersCount = useMemo(() => {
    if (!aggregation || !aggregation.additionalMembers) return 0
    return aggregation.additionalMembers
  }, [aggregation])

  // Get projects resource from aggregation API (resourceId: "projects")
  const projectsResource = useMemo(() => {
    const resources = (aggregation as unknown)?.resources
    if (!Array.isArray(resources)) return null
    return resources.find((r: unknown) => r.resourceId === 'projects') ?? null
  }, [aggregation])

  // Additional projects count and cost from aggregation API when available
  const additionalProjectsCount = useMemo(() => {
    if (
      projectsResource?.value !== undefined &&
      projectsResource?.value !== null
    ) {
      return Number(projectsResource.value)
    }
    // Fallback: derive from breakdown count minus plan included
    if (!plan || !aggregation) return 0
    const includedProjects =
      plan.projects || plan.addons?.projects?.planIncluded || 0
    const projects =
      aggregation.breakdown ||
      aggregation.projects ||
      aggregation.projectBreakdown ||
      []
    const totalProjects = Array.isArray(projects) ? projects.length : 0
    return Math.max(0, totalProjects - includedProjects)
  }, [plan, aggregation, projectsResource])

  const additionalProjectsCost = useMemo(() => {
    if (
      projectsResource?.amount !== undefined &&
      projectsResource?.amount !== null
    ) {
      return Number(projectsResource.amount)
    }
    // Fallback: derive from count * plan addon price
    if (!plan || !aggregation) return 0
    const includedProjects =
      plan.projects || plan.addons?.projects?.planIncluded || 0
    const projects =
      aggregation.breakdown ||
      aggregation.projects ||
      aggregation.projectBreakdown ||
      []
    const totalProjects = Array.isArray(projects) ? projects.length : 0
    if (totalProjects <= includedProjects) return 0
    const additionalCount = totalProjects - includedProjects
    const additionalProjectPrice =
      plan.addons?.projects?.price || plan.additionalProjectPrice || 0
    return additionalCount * additionalProjectPrice
  }, [plan, aggregation, projectsResource])

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

  // Process aggregation breakdown for display
  const projectBreakdowns = useMemo(() => {
    if (!aggregation) return []

    // Try different property names for projects
    const projects =
      aggregation.breakdown ||
      aggregation.projects ||
      aggregation.projectBreakdown ||
      []
    if (!Array.isArray(projects) || projects.length === 0) return []

    return projects.map((project: unknown) => {
      const resources: ResourceItem[] = []
      let projectTotal = 0

      // Resources are in project.resources array with resourceId and value
      const projectResources = Array.isArray(project.resources)
        ? project.resources
        : []

      // Map aggregation resourceIds to our internal keys and display info
      // The aggregation uses resourceId like "bandwidth", "storage", "users", "databasesReads", "GBHours", etc.
      const resourceIdMap: Record<
        string,
        {
          key: string
          name: string
          format: 'bytes' | 'number' | 'sms'
          planKey: string
        }
      > = {
        bandwidth: {
          key: 'bandwidth',
          name: 'Bandwidth',
          format: 'bytes',
          planKey: 'bandwidth',
        },
        storage: {
          key: 'storage',
          name: 'Storage',
          format: 'bytes',
          planKey: 'storage',
        },
        users: {
          key: 'users',
          name: 'Users',
          format: 'number',
          planKey: 'users',
        },
        databasesReads: {
          key: 'databaseReads',
          name: 'Database reads',
          format: 'number',
          planKey: 'databaseReads',
        },
        databasesWrites: {
          key: 'databaseWrites',
          name: 'Database writes',
          format: 'number',
          planKey: 'databaseWrites',
        },
        executions: {
          key: 'executions',
          name: 'Executions',
          format: 'number',
          planKey: 'executions',
        },
        imageTransformations: {
          key: 'imageTransformations',
          name: 'Image transformations',
          format: 'number',
          planKey: 'imageTransformations',
        },
        GBHours: {
          key: 'gbHours',
          name: 'GB-hours',
          format: 'number',
          planKey: 'gbHours',
        },
        authPhone: {
          key: 'authPhone',
          name: 'Phone OTP',
          format: 'sms',
          planKey: 'authPhone',
        },
      }

      // Helper to get resource from aggregation by resourceId
      const getResourceByResourceId = (resourceId: string) => {
        return projectResources.find(
          (r: unknown) => r.resourceId === resourceId,
        )
      }

      // Get plan limits from the plan object
      // Limits are directly on the plan object (e.g., plan.bandwidth, plan.storage)
      // Some limits need unit conversion (bandwidth and storage are in GB)
      const getPlanLimit = (planKey: string): number | null => {
        if (!plan) return null

        // Map our planKey to the plan object property name
        const planPropertyMap: Record<string, string> = {
          bandwidth: 'bandwidth',
          storage: 'storage',
          users: 'users',
          executions: 'executions',
          gbHours: 'GBHours', // Note: capital GB in plan object
          databaseReads: 'databasesReads',
          databaseWrites: 'databasesWrites',
          imageTransformations: 'imageTransformations',
          authPhone: 'authPhone',
        }

        const planProperty = planPropertyMap[planKey] || planKey

        // Get the limit value directly from plan object
        const limitValue = (plan as unknown)[planProperty]

        if (limitValue === null || limitValue === undefined) {
          return null
        }

        const numValue = Number(limitValue)
        if (isNaN(numValue)) {
          return null
        }

        // Convert bandwidth and storage from GB to bytes (multiply by 1 billion)
        if (planKey === 'bandwidth' || planKey === 'storage') {
          return numValue * 1000000000
        }

        return numValue
      }

      // Process each resource type from the aggregation
      Object.entries(resourceIdMap).forEach(
        ([resourceId, { name, format, planKey }]) => {
          const resource = getResourceByResourceId(resourceId)

          // Get usage from resource.value (aggregation format)
          const usage =
            resource?.value !== undefined ? Number(resource.value) : 0

          // Get limit from plan
          const limit = getPlanLimit(planKey)

          // Get cost from resource.amount if available
          const cost =
            resource?.amount !== undefined ? Number(resource.amount) : 0

          // Always show the resource if it exists in aggregation or if plan has a limit
          // This matches the old UI which shows all resources
          if (resource || limit !== null) {
            resources.push({
              name,
              usage,
              limit,
              cost,
              formatType: format,
            })
            projectTotal += cost
          }
        },
      )

      return {
        projectId: project.projectId || project.$id || project.id,
        projectName: project.projectName || project.name || 'Unknown Project',
        resources,
        total: projectTotal,
      }
    })
  }, [aggregation, plan])

  // Total projects: from API resources when available (paginated response), else current page length
  const totalProjects = useMemo(() => {
    if (
      projectsResource?.value !== undefined &&
      projectsResource?.value !== null
    ) {
      return Number(projectsResource.value)
    }
    return projectBreakdowns.length
  }, [projectsResource?.value, projectBreakdowns.length])

  // API returns current page only; we show the displayed page's data (no layout shift until it's loaded)
  const displayedBreakdowns = projectBreakdowns

  const isLoading = orgLoading || planLoading || aggLoading

  if (isLoading) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <div className="h-6 w-32 bg-muted animate-pulse rounded" />
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            Loading plan details...
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      {/* Header */}
      <div className="px-6 py-4">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h3 className="text-[15px] font-semibold text-foreground">
                {planName} plan
              </h3>
              <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                {billingCycleLabel}
              </span>
            </div>
            {totalAmount > 0 && nextPaymentDate && (
              <p className="text-[12px] text-muted-foreground mt-1">
                Next payment of{' '}
                <span className="font-medium text-foreground">
                  {formatCurrency(totalAmount)}
                </span>{' '}
                will occur on{' '}
                <span className="font-medium text-foreground">
                  {formatDate(nextPaymentDate, {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                  })}
                </span>
                .
              </p>
            )}
          </div>
          <div className="text-right shrink-0 flex items-end">
            <p className="text-[11px] text-muted-foreground italic">
              Estimate, subject to change based on usage
            </p>
          </div>
        </div>
      </div>

      {/* Billing Cycle */}
      {billingCycle && (
        <div className="border-t border-border px-6 py-3 bg-muted/30">
          <div className="flex items-center justify-between text-[12px]">
            <span className="text-muted-foreground">Current billing cycle</span>
            <span className="font-medium text-foreground">
              (
              {formatDate(billingCycle.start, {
                month: 'short',
                day: 'numeric',
              })}
              –
              {formatDate(billingCycle.end, { month: 'short', day: 'numeric' })}
              )
            </span>
          </div>
        </div>
      )}

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
            expanded ? 'max-h-[2000px]' : 'max-h-0',
          )}
        >
          <div className="border-t border-border px-6 py-4 space-y-4">
            {/* Base Plan Row */}
            <div className="flex items-center justify-between text-[13px]">
              <span className="text-foreground">{planName} plan (base)</span>
              <span className="font-medium text-foreground">
                {formatCurrency(basePlanPrice)}
              </span>
            </div>

            {/* Additional Members */}
            {additionalMembersCost > 0 && (
              <div className="flex items-center justify-between text-[13px]">
                <span className="text-foreground flex items-center gap-2">
                  Additional members
                  {additionalMembersCount > 0 && (
                    <Badge
                      variant="secondary"
                      className="h-4 px-1.5 text-[10px] font-medium"
                    >
                      {additionalMembersCount}
                    </Badge>
                  )}
                </span>
                <span className="font-medium text-foreground">
                  {formatCurrency(additionalMembersCost)}
                </span>
              </div>
            )}

            {/* Additional Projects */}
            {additionalProjectsCount > 0 && (
              <div className="flex items-center justify-between text-[13px]">
                <span className="text-foreground flex items-center gap-2">
                  Additional projects
                  <Badge
                    variant="secondary"
                    className="h-4 px-1.5 text-[10px] font-medium"
                  >
                    {additionalProjectsCount}
                  </Badge>
                </span>
                <span className="font-medium text-foreground">
                  {formatCurrency(additionalProjectsCost)}
                </span>
              </div>
            )}

            {/* Credits Applied */}
            {creditsApplied > 0 && (
              <div className="flex items-center justify-between text-[13px]">
                <span className="text-muted-foreground">Credits applied</span>
                <span className="font-medium text-foreground text-green-600 dark:text-green-400">
                  -{formatCurrency(creditsApplied)}
                </span>
              </div>
            )}

            {/* Project Breakdown Section */}
            {projectBreakdowns.length > 0 && (
              <div className="space-y-2">
                <div className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                  Project breakdown
                </div>
                <div className="space-y-1">
                  {displayedBreakdowns.map((project) => (
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
                        <div className="ml-5 mt-1 border-l border-border pl-3 pr-6 pb-2">
                          <div className="space-y-0.5">
                            {project.resources.map((resource, index) => {
                              const usagePercentage =
                                resource.limit && resource.limit > 0
                                  ? Math.min(
                                      100,
                                      (resource.usage / resource.limit) * 100,
                                    )
                                  : null
                              const usageFormatted = formatResourceUsage(
                                resource.usage,
                                resource.formatType,
                              )
                              const limitFormatted =
                                resource.limit !== null
                                  ? formatResourceLimit(
                                      resource.limit,
                                      resource.formatType,
                                    )
                                  : 'Unlimited'

                              return (
                                <div
                                  key={index}
                                  className="py-2 border-b border-border last:border-0"
                                >
                                  <div className="flex items-center gap-6">
                                    {/* Resource name - fixed width */}
                                    <span className="text-[12px] font-medium text-foreground w-[140px] shrink-0">
                                      {resource.name}
                                    </span>

                                    {/* Progress bar - fixed width column for alignment */}
                                    <div className="w-[120px] shrink-0">
                                      {usagePercentage !== null ? (
                                        <TooltipProvider>
                                          <Tooltip>
                                            <TooltipTrigger asChild>
                                              <div>
                                                <Progress
                                                  value={usagePercentage}
                                                  className={cn(
                                                    'h-2 cursor-pointer',
                                                    usagePercentage >= 80 &&
                                                      '[&>div]:bg-blue-500',
                                                  )}
                                                />
                                              </div>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                              <p className="text-[12px]">
                                                {usagePercentage.toFixed(1)}%
                                                used
                                              </p>
                                            </TooltipContent>
                                          </Tooltip>
                                        </TooltipProvider>
                                      ) : (
                                        <div className="h-2" /> // Spacer to maintain alignment
                                      )}
                                    </div>

                                    {/* Usage/limit text - flexible; when plan has 0 included quota, show count only */}
                                    <span className="text-[11px] text-muted-foreground whitespace-nowrap flex-1 min-w-0">
                                      {resource.limit === 0
                                        ? usageFormatted
                                        : `${usageFormatted} / ${limitFormatted}`}
                                    </span>

                                    {/* Cost - right aligned to match parent prices */}
                                    <span className="text-[12px] font-medium text-foreground shrink-0 text-right min-w-[70px]">
                                      {formatCurrency(resource.cost)}
                                    </span>
                                  </div>
                                </div>
                              )
                            })}
                          </div>
                          {orgId && (
                            <Link
                              to="/projects/$projectId/usage"
                              params={{ projectId: project.projectId }}
                              className="flex items-center gap-1 text-[11px] text-primary hover:underline mt-2"
                            >
                              Usage details
                              <ExternalLink className="h-3 w-3" />
                            </Link>
                          )}
                        </div>
                      </CollapsibleContent>
                    </Collapsible>
                  ))}
                </div>

                {/* Pagination: standard component; current results stay until new page has loaded */}
                {totalProjects > pageLimit && (
                  <div className="pt-2 border-t border-border">
                    <Pagination
                      currentPage={displayedPage}
                      totalItems={totalProjects}
                      pageSize={pageLimit}
                      onPageChange={(page) => {
                        navigate({
                          search: (prev: unknown) => ({
                            ...(typeof prev === 'object' && prev !== null
                              ? (prev as Record<string, unknown>)
                              : {}),
                            page,
                            limit: pageLimit,
                          }) as never,
                        })
                      }}
                      onPageSizeChange={() => {}}
                      showPageSizeSelector={false}
                      itemLabel="projects"
                      className="flex-wrap"
                    />
                  </div>
                )}
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
      {plan?.selfService !== false && (
        <div className="border-t border-border px-6 py-4 bg-muted/30">
          <div className="flex items-center gap-2">
            {(basePlanPrice ?? 0) === 0 ? (
              <Button
                size="sm"
                className="h-9 text-[13px] gap-1.5"
                onClick={onChangePlan}
              >
                <ArrowUpCircle className="h-4 w-4" />
                Upgrade
              </Button>
            ) : (
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px] gap-1.5"
                onClick={onChangePlan}
              >
                <ArrowLeftRight className="h-4 w-4" />
                Change plan
              </Button>
            )}
            {organization?.billingPlanDowngrade && (
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={onChangePlan}
              >
                Cancel change
              </Button>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

// Helper functions
function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1000
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`
}

function formatNumber(num: number): string {
  if (num >= 1000000) {
    return `${(num / 1000000).toFixed(1)}M`
  }
  if (num >= 1000) {
    return `${(num / 1000).toFixed(1)}K`
  }
  return num.toLocaleString()
}

function formatResourceUsage(
  value: number,
  type: 'bytes' | 'number' | 'sms',
): string {
  if (type === 'bytes') {
    return formatBytes(value)
  }
  if (type === 'sms') {
    return `${value.toLocaleString()} SMS messages`
  }
  return formatNumber(value)
}

function formatResourceLimit(
  value: number | null,
  type: 'bytes' | 'number' | 'sms',
): string {
  if (value === null) return 'Unlimited'
  if (type === 'bytes') {
    // For limits, show in GB if large enough
    if (value >= 1000 * 1000 * 1000) {
      return `${(value / (1000 * 1000 * 1000)).toFixed(0)} GB`
    }
    return formatBytes(value)
  }
  if (type === 'sms') {
    return `${value.toLocaleString()}`
  }
  return formatNumber(value)
}
