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
import { formatDecimalBytes, toByteCount } from '@/lib/utils/byte-display-unit'
import { formatProjectNameForDisplay } from '@/lib/react-query/hooks/projects'
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
import {
  getPlanNameFromTier,
  resolveOrganizationPlanDisplayLabel,
} from '@/lib/utils/plan-filter'
import { Link } from '@tanstack/react-router'
import { Pagination } from '@/components/global/shared/Pagination'
import type { Models } from '@appwrite.io/console'
import {
  buildDedicatedDbBillingSpecLookup,
  buildOrganizationUsageCategoriesFromAggregation,
  DEDICATED_DB_BILLING_METRIC_IDS,
  formatDedicatedDbBillingUsageLabel,
  getBillingProjectResourceIdMap,
  getDedicatedDbBillingUsageDescription,
  groupBillingProjectResources,
  groupDedicatedDbBillingResources,
  parseDedicatedDbBillingResourceId,
  resolveBillingProjectResourceMapping,
  type BillingProjectBreakdown,
  type BillingProjectResourceCategoryGroup,
  type BillingProjectResourceItem,
  type DedicatedDbBillingSpecGroup,
} from '@/lib/billing/project-breakdown-resources'
import {
  getBillingAddonChargesFromResources,
  getDedicatedDbComputeCreditFromResources,
  resolveBillingAddonDisplayName,
} from '@/lib/billing/billing-addon-charges'
import { databaseSpecificationsQueryOptions, dedicatedDatabaseSourceFromEngine } from '@/lib/react-query/hooks'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

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

export function PlanSummary({ onChangePlan, orgId }: PlanSummaryProps) {
  const t = useT()
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

  const dedicatedDbSpecLookupProjectId = useMemo(() => {
    const projects = aggregation?.breakdown
    if (!Array.isArray(projects) || projects.length === 0) return null
    const firstProjectId = projects[0]?.$id
    return typeof firstProjectId === 'string' && firstProjectId.length > 0
      ? firstProjectId
      : null
  }, [aggregation?.breakdown])

  const { data: databaseSpecificationsData } = useQuery(
    databaseSpecificationsQueryOptions(
      dedicatedDbSpecLookupProjectId,
      dedicatedDatabaseSourceFromEngine('postgresql'),
    ),
  )

  const dedicatedDbBillingSpecLookup = useMemo(
    () =>
      buildDedicatedDbBillingSpecLookup(
        databaseSpecificationsData?.specifications,
      ),
    [databaseSpecificationsData?.specifications],
  )

  // Calculate available credit
  const availableCredit = useMemo(() => {
    if (!credits || credits.length === 0) return 0
    const now = new Date()
    return credits.reduce((sum, credit) => {
      if (credit.expiration && new Date(credit.expiration) > now) {
        return sum + (credit.credits || 0)
      }
      return sum
    }, 0)
  }, [credits])

  // Get plan name from plan object
  const planName = useMemo(() => {
    if (!organization) return 'Free'
    const planMatchesOrg =
      !plan?.$id ||
      plan.$id === organization.billingPlan ||
      getPlanNameFromTier(plan.$id) ===
        getPlanNameFromTier(organization.billingPlan)
    return resolveOrganizationPlanDisplayLabel({
      billingPlan: organization.billingPlan,
      planName: planMatchesOrg ? (plan?.name ?? null) : null,
      planId: planMatchesOrg ? plan?.$id : organization.billingPlan,
    })
  }, [plan, organization])

  // Get base plan price
  const basePlanPrice = useMemo(() => {
    if (!plan) return 0
    return plan.price || 0
  }, [plan])

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

  // Free / non-usagePerProject plans: aggregation.breakdown is empty; org totals live in resources.
  const usagePerProject = plan?.usagePerProject === true

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
    return (
      (plan as Models.BillingPlan & { billingCycle?: string }).billingCycle ||
      'Monthly'
    )
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
    if (!aggregation?.resources) return null
    return (
      aggregation.resources.find((r) => r.resourceId === 'projects') ?? null
    )
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
    const projects = aggregation.breakdown || []
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
    const projects = aggregation.breakdown || []
    const totalProjects = Array.isArray(projects) ? projects.length : 0
    if (totalProjects <= includedProjects) return 0
    const additionalCount = totalProjects - includedProjects
    const additionalProjectPrice = plan.addons?.projects?.price || 0
    return additionalCount * additionalProjectPrice
  }, [plan, aggregation, projectsResource])

  // Toggle addons (BAA, Premium Geo DB, …) from aggregation resources
  const billingAddonCharges = useMemo(
    () => getBillingAddonChargesFromResources(aggregation?.resources),
    [aggregation?.resources],
  )

  const dedicatedDbComputeCredit = useMemo(
    () => getDedicatedDbComputeCreditFromResources(aggregation?.resources),
    [aggregation?.resources],
  )

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

  // Process aggregation breakdown for display (usagePerProject / Pro+ plans)
  const projectBreakdowns = useMemo((): BillingProjectBreakdown[] => {
    if (!aggregation) return []

    const projects = aggregation.breakdown || []
    if (!Array.isArray(projects) || projects.length === 0) return []

    return projects.map((project) => {
      const resources: BillingProjectResourceItem[] = []
      let projectTotal = 0

      // Resources are in project.resources array with resourceId and value
      const projectResources = Array.isArray(project.resources)
        ? project.resources
        : []

      const resourceIdMap = getBillingProjectResourceIdMap(plan)
      const hasSpecSpecificDedicatedDbResources = projectResources.some(
        (resource) =>
          typeof resource.resourceId === 'string' &&
          parseDedicatedDbBillingResourceId(resource.resourceId) != null,
      )

      // Helper to get resource from aggregation by resourceId
      const getResourceByResourceId = (resourceId: string) => {
        return projectResources.find((r) => r.resourceId === resourceId)
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
          screenshotsGenerated: 'screenshotsGenerated',
          authPhone: 'authPhone',
          realtime: 'realtime',
          realtimeMessages: 'realtimeMessages',
          realtimeBandwidth: 'realtimeBandwidth',
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

      // Keep the API resource order first, then append known resources that are not present.
      const apiOrderedResourceIds = projectResources
        .map((resource) => resource.resourceId)
        .filter(
          (resourceId): resourceId is string =>
            typeof resourceId === 'string' && resourceId.length > 0,
        )
      const fallbackResourceIds = Object.keys(resourceIdMap).filter(
        (resourceId) => {
          if (
            hasSpecSpecificDedicatedDbResources &&
            resourceId.startsWith('dedicatedDb')
          ) {
            return false
          }
          return true
        },
      )
      const orderedResourceIds = Array.from(
        new Set([...apiOrderedResourceIds, ...fallbackResourceIds]),
      )

      // Process each resource type from the aggregation
      orderedResourceIds.forEach((resourceId) => {
        if (
          hasSpecSpecificDedicatedDbResources &&
          (DEDICATED_DB_BILLING_METRIC_IDS as readonly string[]).includes(
            resourceId,
          )
        ) {
          return
        }

        const mappedResource = resolveBillingProjectResourceMapping(
          resourceId,
          plan,
          dedicatedDbBillingSpecLookup,
        )
        if (!mappedResource) return

        const {
          name,
          format,
          planKey,
          category,
          showLimit = true,
          showOnlyWhenUsed = false,
        } = mappedResource
        const resource = getResourceByResourceId(resourceId)

        // Get usage from resource.value (aggregation format)
        const usage = resource?.value !== undefined ? Number(resource.value) : 0

        // Get limit from plan
        const limit = getPlanLimit(planKey)
        const requiresUpgrade = limit !== null && limit < 0

        // Get cost from resource.amount if available
        const cost =
          resource?.amount !== undefined ? Number(resource.amount) : 0

        const hasUsageOrCost = usage > 0 || cost > 0
        const shouldShow = showOnlyWhenUsed
          ? resource != null && hasUsageOrCost
          : resource != null || limit !== null || requiresUpgrade

        // Always show the resource if it exists in aggregation or if plan has a limit
        // This matches the old UI which shows all resources
        if (shouldShow) {
          resources.push({
            resourceId,
            name,
            usage,
            limit: requiresUpgrade ? null : limit,
            cost,
            formatType: format,
            showLimit: requiresUpgrade ? false : showLimit,
            requiresUpgrade,
            category,
          })
          projectTotal += cost
        }
      })

      // Project-scoped billing addons (e.g. Premium Geo DB)
      projectResources
        .filter(
          (resource) =>
            typeof resource.resourceId === 'string' &&
            resource.resourceId.startsWith('addon_') &&
            Number(resource.amount) > 0,
        )
        .forEach((addon) => {
          const resourceId = addon.resourceId as string
          const cost = Number(addon.amount) || 0
          resources.push({
            resourceId,
            name: resolveBillingAddonDisplayName({
              resourceId,
              name: addon.name,
            }),
            usage: Number(addon.value) || 0,
            limit: null,
            cost,
            formatType: 'number',
            showLimit: false,
            category: 'addons',
          })
          projectTotal += cost
        })

      return {
        projectId: project.$id,
        projectName: project.name || t('Unknown Project'),
        categories: groupBillingProjectResources(resources),
        // Prefer aggregation project amount so the row matches billed totals
        // (recalculated resource sums can miss newer metric ids).
        total: Number.isFinite(Number(project.amount))
          ? Number(project.amount)
          : projectTotal,
      }
    })
  }, [aggregation, plan, dedicatedDbBillingSpecLookup, t])

  // Org-scoped plan caps (Free / non-usagePerProject): aggregation.resources.
  // Always show the full base resource list; disabled plan resources (-1) render
  // an Upgrade link instead of usage. Bump listVersion when the list shape changes
  // so Fast Refresh does not keep a stale memoized result.
  const organizationUsageListVersion = 2
  const organizationUsageCategories = useMemo(() => {
    if (usagePerProject) return []
    return buildOrganizationUsageCategoriesFromAggregation(
      aggregation?.resources,
      plan,
    )
  }, [
    usagePerProject,
    aggregation?.resources,
    plan,
    organizationUsageListVersion,
  ])

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

  // Aggregation API returns current page only
  const displayedBreakdowns = projectBreakdowns

  const isLoading =
    (orgLoading && !organization) ||
    (planLoading && !plan) ||
    (aggLoading && !aggregation && !!organization?.billingAggregationId)

  if (isLoading) {
    return (
      <div
        id="current-cycle-usage"
        className="rounded-xl border border-border bg-card/50 overflow-hidden scroll-mt-24"
      >
        <div className="px-6 py-4">
          <div className="h-6 w-32 bg-muted animate-pulse rounded" />
        </div>
        <div className="border-t border-border px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            {t('Loading plan details...')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div
      id="current-cycle-usage"
      className="rounded-xl border border-border bg-card/50 overflow-hidden scroll-mt-24"
    >
      {/* Header */}
      <div className="px-6 py-4">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h3 className="text-[15px] font-semibold text-foreground">
                {planName} {t('plan')}
              </h3>
              <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                {billingCycleLabel}
              </span>
            </div>
            {totalAmount > 0 && nextPaymentDate && (
              <p className="text-[12px] text-muted-foreground mt-1">
                {t('Next payment of')}{' '}
                <span className="font-medium text-foreground">
                  {formatCurrency(totalAmount)}
                </span>{' '}
                {t('will occur on')}{' '}
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
          <div className="text-end shrink-0 flex items-end">
            <p className="text-[11px] text-muted-foreground italic">
              {t('Estimate, subject to change based on usage')}
            </p>
          </div>
        </div>
      </div>

      {/* Billing Cycle */}
      {billingCycle && (
        <div className="border-t border-border px-6 py-3 bg-muted/30">
          <div className="flex items-center justify-between text-[12px]">
            <span className="text-muted-foreground">{t('Current billing cycle')}</span>
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
          <span>{t('View charges breakdown')}</span>
          {expanded ? (
            <ChevronUp className="h-4 w-4" />
          ) : (
            <ChevronDown className="h-4 w-4" />
          )}
        </button>

        <div
          className={cn(
            'transition-all duration-200',
            expanded
              ? 'max-h-none overflow-visible'
              : 'max-h-0 overflow-hidden',
          )}
        >
          <div className="border-t border-border px-6 py-4 space-y-4">
            {/* Base Plan Row */}
            <div className="flex items-center justify-between text-[13px]">
              <span className="text-foreground">{planName} {t('plan (base)')}</span>
              <span className="font-medium text-foreground">
                {formatCurrency(basePlanPrice)}
              </span>
            </div>

            {/* Additional Members */}
            {additionalMembersCost > 0 && (
              <div className="flex items-center justify-between text-[13px]">
                <span className="text-foreground flex items-center gap-2">
                  {t('Additional members')}
                  {additionalMembersCount > 0 && (
                    <Badge
                      variant="info"
                      className="h-4 px-1.5 text-[10px] font-medium shrink-0"
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
                  {t('Additional projects')}
                  <Badge
                    variant="info"
                    className="h-4 px-1.5 text-[10px] font-medium shrink-0"
                  >
                    {additionalProjectsCount}
                  </Badge>
                </span>
                <span className="font-medium text-foreground">
                  {formatCurrency(additionalProjectsCost)}
                </span>
              </div>
            )}

            {/* Billing addons (BAA, Premium Geo DB, …) */}
            {billingAddonCharges.map((addon) => (
              <div
                key={addon.resourceId}
                className="flex items-center justify-between text-[13px]"
              >
                <span className="text-foreground">{t(addon.name)}</span>
                <span className="font-medium text-foreground">
                  {formatCurrency(addon.amount)}
                </span>
              </div>
            ))}

            {/* Dedicated DB included compute credit */}
            {dedicatedDbComputeCredit ? (
              <div className="flex items-center justify-between text-[13px]">
                <span className="text-foreground">
                  {t(dedicatedDbComputeCredit.name)}
                </span>
                <span className="font-medium text-foreground">
                  {formatCurrency(dedicatedDbComputeCredit.amount)}
                </span>
              </div>
            ) : null}

            {/* Credits Applied */}
            {creditsApplied > 0 && (
              <div className="flex items-center justify-between text-[13px]">
                <span className="text-muted-foreground">{t('Credits applied')}</span>
                <span className="font-medium text-foreground text-green-600 dark:text-green-400">
                  -{formatCurrency(creditsApplied)}
                </span>
              </div>
            )}

            {/* Org-level usage vs plan limits (Free / non-usagePerProject) */}
            {organizationUsageCategories.length > 0 && (
              <div className="space-y-2">
                <div className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                  {t('Organization usage')}
                </div>
                <div className="divide-y divide-border">
                  {organizationUsageCategories.map((category) => (
                    <BillingProjectResourceCategorySection
                      key={category.id}
                      category={category}
                      plan={plan}
                      dedicatedDbBillingSpecLookup={
                        dedicatedDbBillingSpecLookup
                      }
                      onUpgrade={onChangePlan}
                    />
                  ))}
                </div>
              </div>
            )}

            {/* Project Breakdown Section */}
            {projectBreakdowns.length > 0 && (
              <div className="space-y-2">
                <div className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                  {t('Project breakdown')}
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
                          <span
                            className="flex items-center gap-2 text-foreground min-w-0"
                            title={project.projectName}
                          >
                            <Folder className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                            <span className="truncate">
                              {formatProjectNameForDisplay(project.projectName)}
                            </span>
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
                        <div className="ms-5 mt-1 border-s border-border ps-3 pe-6 pb-2">
                          <div className="divide-y divide-border">
                            {project.categories.map((category) => (
                              <BillingProjectResourceCategorySection
                                key={category.id}
                                category={category}
                                plan={plan}
                                dedicatedDbBillingSpecLookup={
                                  dedicatedDbBillingSpecLookup
                                }
                              />
                            ))}
                          </div>
                          {orgId && (
                            <Link
                              to="/projects/$projectId/usage"
                              params={{ projectId: project.projectId }}
                              className="flex items-center gap-1 text-[11px] link-neutral mt-2"
                            >
                              {t('Usage details')}
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
                          search: (prev: unknown) =>
                            ({
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
                      itemLabel={t('projects')}
                      className="flex-wrap"
                    />
                  </div>
                )}
              </div>
            )}

            {/* Total */}
            <div className="flex items-center justify-between border-t border-border pt-3 text-[13px]">
              <span className="font-medium text-foreground">{t('Total')}</span>
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
                {...analyticsAttrs('upgrade-clicked')}
              >
                <ArrowUpCircle className="h-4 w-4" />
                {t('Upgrade')}
              </Button>
            ) : (
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px] gap-1.5"
                onClick={onChangePlan}
                {...analyticsAttrs('billing-change-plan')}
              >
                <ArrowLeftRight className="h-4 w-4" />
                {t('Change plan')}
              </Button>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

function BillingProjectResourceCategorySection({
  category,
  plan,
  dedicatedDbBillingSpecLookup,
  onUpgrade,
}: {
  category: BillingProjectResourceCategoryGroup
  plan: Models.BillingPlan | null | undefined
  dedicatedDbBillingSpecLookup: ReturnType<
    typeof buildDedicatedDbBillingSpecLookup
  >
  onUpgrade?: () => void
}) {
  const t = useT()
  if (category.id === 'dedicated-databases') {
    const { specGroups, ungrouped } = groupDedicatedDbBillingResources(
      category.resources,
      plan,
      dedicatedDbBillingSpecLookup,
    )

    return (
      <div className="py-3 first:pt-0 last:pb-0">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground pb-1.5">
          {t(category.label)}
        </p>
        <div className="space-y-2">
          {specGroups.map((group) => (
            <BillingDedicatedDbSpecGroup
              key={group.key}
              group={group}
            />
          ))}
          {ungrouped.map((resource) => (
            <BillingProjectResourceRow
              key={resource.resourceId}
              resource={resource}
              onUpgrade={onUpgrade}
            />
          ))}
        </div>
      </div>
    )
  }

  return (
    <div className="py-3 first:pt-0 last:pb-0">
      <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground pb-1.5">
        {t(category.label)}
      </p>
      <div className="space-y-1">
        {category.resources.map((resource) => (
          <BillingProjectResourceRow
            key={resource.resourceId}
            resource={resource}
            onUpgrade={onUpgrade}
          />
        ))}
      </div>
    </div>
  )
}

function BillingDedicatedDbSpecGroup({
  group,
}: {
  group: DedicatedDbBillingSpecGroup
}) {
  const t = useT()

  // Always use the nested group card, even when only compute remains after
  // zero-usage siblings are filtered out (e.g. Standard with 0 storage/bandwidth).
  // Flattening single-item groups made those tiers look inconsistent with Starter.
  return (
    <div className="rounded-lg border border-border/70 bg-muted/15 overflow-hidden">
      <div className="flex items-center justify-between gap-4 px-3 py-2 border-b border-border/70">
        <span className="text-[12px] font-medium text-foreground truncate">
          {group.title}
        </span>
        <span className="text-[12px] font-semibold text-foreground shrink-0 tabular-nums">
          {formatCurrency(group.totalCost)}
        </span>
      </div>
      <div className="px-3 py-1.5 border-b border-border/50">
        <div className="flex items-center justify-end gap-4">
          <span className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground min-w-[88px] text-end">
            {t('Usage')}
          </span>
          <span className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground min-w-[70px] text-end">
            {t('Cost')}
          </span>
        </div>
      </div>
      <div className="divide-y divide-border/50">
        {group.items.map((item) => (
          <BillingDedicatedDbMetricRow key={item.resourceId} item={item} />
        ))}
      </div>
    </div>
  )
}

function BillingDedicatedDbMetricRow({
  item,
}: {
  item: DedicatedDbBillingSpecGroup['items'][number]
}) {
  const usageLabel = formatDedicatedDbBillingUsageLabel(
    item.usage,
    item.metricId,
    item.formatType,
  )
  const usageDescription = getDedicatedDbBillingUsageDescription(
    item.metricId,
    item.formatType,
  )

  return (
    <div className="flex items-center justify-between gap-4 px-3 py-1.5">
      <span className="text-[11px] text-muted-foreground truncate">
        {item.metricLabel}
      </span>
      <div className="flex items-center gap-4 shrink-0">
        {usageDescription ? (
          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="text-[11px] text-muted-foreground tabular-nums min-w-[88px] text-end underline decoration-dotted decoration-muted-foreground/50 underline-offset-2 cursor-help">
                  {usageLabel}
                </span>
              </TooltipTrigger>
              <TooltipContent side="top" className="max-w-[240px]">
                <p className="text-[12px]">{usageDescription}</p>
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
        ) : (
          <span className="text-[11px] text-muted-foreground tabular-nums min-w-[88px] text-end">
            {usageLabel}
          </span>
        )}
        <span className="text-[11px] font-medium text-foreground tabular-nums min-w-[70px] text-end">
          {formatCurrency(item.cost)}
        </span>
      </div>
    </div>
  )
}

function BillingProjectResourceRow({
  resource,
  onUpgrade,
}: {
  resource: BillingProjectResourceItem
  onUpgrade?: () => void
}) {
  const t = useT()
  const usage = toByteCount(resource.usage)
  const limit = resource.limit == null ? null : toByteCount(resource.limit)
  const usagePercentage =
    limit && limit > 0
      ? Math.min(100, (usage / limit) * 100)
      : null
  const usageFormatted =
    resource.usageLabel ??
    formatResourceUsage(usage, resource.formatType)
  const limitFormatted =
    limit !== null
      ? formatResourceLimit(limit, resource.formatType)
      : t('Unlimited')

  const usageContent = resource.usageDescription ? (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="underline decoration-dotted decoration-muted-foreground/50 underline-offset-2 cursor-help">
            {!resource.showLimit
              ? usageFormatted
              : limit === 0
                ? usageFormatted
                : `${usageFormatted} / ${limitFormatted}`}
          </span>
        </TooltipTrigger>
        <TooltipContent side="top" className="max-w-[240px]">
          <p className="text-[12px]">{resource.usageDescription}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  ) : (
    <>
      {!resource.showLimit
        ? usageFormatted
        : limit === 0
          ? usageFormatted
          : `${usageFormatted} / ${limitFormatted}`}
    </>
  )

  return (
    <div className="py-1.5">
      <div className="flex items-center gap-6">
        <span className="text-[12px] font-medium text-foreground w-[140px] shrink-0">
          {t(resource.name)}
        </span>

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
                        usagePercentage >= 80 && '[&>div]:bg-blue-500',
                      )}
                    />
                  </div>
                </TooltipTrigger>
                <TooltipContent>
                  <p className="text-[12px]">
                    {usagePercentage.toFixed(1)}% {t('used')}
                  </p>
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          ) : (
            <div className="h-2" />
          )}
        </div>

        <span className="text-[11px] text-muted-foreground whitespace-nowrap flex-1 min-w-0">
          {resource.requiresUpgrade ? (
            onUpgrade ? (
              <button
                type="button"
                className="link-neutral text-[11px]"
                onClick={onUpgrade}
              >
                {t('Upgrade')}
              </button>
            ) : (
              t('Upgrade')
            )
          ) : (
            usageContent
          )}
        </span>

        <span className="text-[12px] font-medium text-foreground shrink-0 text-end min-w-[70px]">
          {resource.cost > 0 ? formatCurrency(resource.cost) : null}
        </span>
      </div>
    </div>
  )
}

// Helper functions
function formatBytes(bytes: number | bigint): string {
  return formatDecimalBytes(bytes)
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
