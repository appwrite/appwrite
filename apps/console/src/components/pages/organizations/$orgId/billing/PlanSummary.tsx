import { useMemo, useState } from 'react'
import { useSearch, useNavigate } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { Progress } from '@/components/ui/progress'
import { Badge } from '@/components/ui/badge'
import { Pagination } from '@/components/global/shared/Pagination'
import { BillingStorageUsageProgress } from './_components/BillingStorageUsageProgress'
import { getBillingStorageBreakdownFromResources } from '@/lib/billing/billing-storage-breakdown'
import { formatDecimalBytes, toByteCount } from '@/lib/utils/byte-display-unit'
import { formatProjectNameForDisplay } from '@/lib/react-query/hooks/projects'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
  TooltipProvider,
} from '@/components/ui/tooltip'
import {
  buildDedicatedDbBillingSpecLookup,
  getBillingProjectResourceIdMap,
  getBillingPlanResourceLimit,
  isBillingPlanResourceUnavailable,
  resolveBillingProjectResourceMapping,
  groupBillingProjectResources,
  groupDedicatedDbBillingResources,
  formatDedicatedDbBillingUsageLabel,
  getDedicatedDbBillingUsageDescription,
  type AggregationResourceLike,
  type BillingProjectResourceItem,
  type BillingProjectResourceCategoryGroup,
  type DedicatedDbBillingSpecGroup,
} from '@/lib/billing/project-breakdown-resources'
import {
  databaseSpecificationsQueryOptions,
  dedicatedDatabaseSourceFromEngine,
} from '@/lib/react-query/hooks'
import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import {
  ChevronDown,
  ChevronUp,
  ArrowUpCircle,
  ArrowLeftRight,
  Folder,
  ExternalLink,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { formatCurrency, formatDate } from './utils'
import { cn } from '@/lib/utils'
import {
  useOrganizationById,
  useOrganizationPlan,
  organizationInvoiceQueryOptions,
  organizationBillingAggregationQueryOptions,
  useOrganizationScopes,
  canSeeOrganizationBilling,
} from '@/lib/react-query/hooks'
import {
  getPlanNameFromTier,
  isSelfServiceProPlan,
  resolveOrganizationPlanDisplayLabel,
} from '@/lib/utils/plan-filter'
import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { EnterpriseBadge } from '@/components/global/shared/EnterpriseBadge'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

interface PlanSummaryProps {
  onChangePlan?: () => void
  orgId?: string
}

export function PlanSummary({ onChangePlan, orgId }: PlanSummaryProps) {
  const t = useT()
  const [expanded, setExpanded] = useState(true)
  const [expandedProjects, setExpandedProjects] = useState<Set<string>>(
    new Set(),
  )
  const search = useSearch({ strict: false })
  const navigate = useNavigate()
  const pageLimit = 10
  const displayedPage = Math.max(1, Number(search?.page) || 1)
  const { organization } = useOrganizationById(orgId)
  const { plan } = useOrganizationPlan(orgId)
  const { access } = useOrganizationScopes(orgId, undefined, {
    projectId: null,
  })
  const canReadInvoice = canSeeOrganizationBilling(access)
  const { data: invoice, error } = useQuery({
    ...organizationInvoiceQueryOptions(orgId, organization?.billingInvoiceId),
    enabled: canReadInvoice && !!orgId && !!organization?.billingInvoiceId,
  })
  const { data: aggregation, error: usageError } = useQuery({
    ...organizationBillingAggregationQueryOptions(
      orgId,
      organization?.billingAggregationId,
    ),
    enabled: canReadInvoice && !!orgId && !!organization?.billingAggregationId,
  })
  const { data: databaseSpecificationsData } = useQuery(
    databaseSpecificationsQueryOptions(
      aggregation?.breakdown[0]?.$id,
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
  const lines = useMemo(() => invoice?.items ?? [], [invoice?.items])
  const {
    projectBreakdowns,
    organizationLines,
    organizationUsageCategories,
    organizationItems,
  } = useMemo(() => {
    const projectIds = [
      ...new Set([
        ...(aggregation?.breakdown ?? []).map((project) => project.$id),
        ...lines.flatMap((item) => (item.projectId ? [item.projectId] : [])),
      ]),
    ]
    const projectBreakdowns = projectIds.map((projectId) => {
      const project = aggregation?.breakdown.find(
        (project) => project.$id === projectId,
      )
      const items = lines.filter((item) => item.projectId === projectId)
      return {
        projectId,
        projectName: project?.name || projectId,
        items,
        categories: usageCategories(
          project?.resources,
          items,
          plan,
          dedicatedDbBillingSpecLookup,
        ),
        total: items.reduce((sum, item) => sum + item.amount, 0) / 100,
      }
    })
    const organizationLines = lines.filter((item) => !item.projectId)
    const organizationUsageCategories =
      plan?.usagePerProject || (!aggregation && organizationLines.length === 0)
        ? []
        : usageCategories(
            aggregation?.resources,
            organizationLines,
            plan,
            dedicatedDbBillingSpecLookup,
          )
    const organizationItems = organizationLines.filter(
      (item) =>
        !organizationUsageCategories.some((category) =>
          category.resources.some(
            (resource) => resource.resourceId === item.resourceId,
          ),
        ),
    )
    return {
      projectBreakdowns,
      organizationLines,
      organizationUsageCategories,
      organizationItems,
    }
  }, [aggregation, lines, plan, dedicatedDbBillingSpecLookup])
  const totalProjects = projectBreakdowns.length
  const displayedBreakdowns = projectBreakdowns.slice(
    (displayedPage - 1) * pageLimit,
    displayedPage * pageLimit,
  )
  const toggleProject = (id: string) =>
    setExpandedProjects((previous) => {
      const next = new Set(previous)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  const planMatchesOrg =
    !plan?.$id ||
    plan.$id === organization?.billingPlan ||
    getPlanNameFromTier(plan.$id) ===
      getPlanNameFromTier(organization?.billingPlan)
  const planName = resolveOrganizationPlanDisplayLabel({
    billingPlan: organization?.billingPlan,
    planName: planMatchesOrg ? plan?.name : null,
    planId: planMatchesOrg ? plan?.$id : organization?.billingPlan,
  })
  const hasRecordedCharges =
    !!invoice &&
    (!!invoice.items?.length || !['pending', 'trial'].includes(invoice.status))
  const basePlanPrice = plan?.price ?? 0
  // Saved lines include pricing adjustments and tax. Account credits settle them separately.
  const chargesInCents = invoice?.items?.length
    ? invoice.items.reduce((total, item) => total + item.amount, 0)
    : Math.round(
        ((invoice?.grossAmount ?? 0) + (invoice?.creditsUsed ?? 0)) * 100,
      )
  const creditsInCents = Math.round((invoice?.creditsUsed ?? 0) * 100)
  const charges = chargesInCents / 100
  const creditsApplied = creditsInCents / 100
  const totalAmount = Math.max(chargesInCents - creditsInCents, 0) / 100
  const currency = invoice?.currency || 'USD'
  const showEnterpriseNote = isSelfServiceProPlan(plan) && charges >= 50
  const billingCycle =
    organization?.billingCurrentInvoiceDate &&
    organization?.billingNextInvoiceDate
      ? {
          start: organization.billingCurrentInvoiceDate,
          end: organization.billingNextInvoiceDate,
        }
      : null
  const nextPaymentDate = invoice?.dueAt ?? billingCycle?.end
  const billingCycleLabel = t('Monthly')

  if (error) throw error
  if (usageError) throw usageError
  if (!canReadInvoice) return null

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
            {hasRecordedCharges &&
              ['pending', 'trial'].includes(invoice?.status ?? '') &&
              totalAmount > 0 &&
              nextPaymentDate && (
                <p className="text-[12px] text-muted-foreground mt-1">
                  {t('Next payment of')}{' '}
                  <span className="font-medium text-foreground">
                    {formatCurrency(totalAmount, currency)}
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
          <div className="text-end shrink-0 max-w-[240px] sm:max-w-xs">
            <p className="text-[12px] leading-snug text-muted-foreground">
              {t('Usage-based estimate; updates may take up to 4 hours.')}
            </p>
          </div>
        </div>
      </div>

      {/* Billing Cycle */}
      {billingCycle && (
        <div className="border-t border-border px-6 py-3 bg-muted/30">
          <div className="flex items-center justify-between text-[12px]">
            <span className="text-muted-foreground">
              {t('Current billing cycle')}
            </span>
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
          className="flex w-full items-center justify-between gap-3 px-6 py-3 text-[13px] text-muted-foreground hover:bg-accent/50 transition-colors"
        >
          <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-start">
            <span>{t('View charges breakdown')}</span>
            {invoice?.$updatedAt ? (
              <>
                <span className="text-border hidden sm:inline" aria-hidden>
                  ·
                </span>
                <span className="text-[11px]">
                  {t('Last updated')}:{' '}
                  <DateTooltip
                    date={invoice.$updatedAt}
                    live
                    className="text-muted-foreground"
                  />
                </span>
              </>
            ) : null}
          </span>
          {expanded ? (
            <ChevronUp className="h-4 w-4 shrink-0" />
          ) : (
            <ChevronDown className="h-4 w-4 shrink-0" />
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
            {invoice?.items?.length ? (
              organizationItems.map((item, index) => (
                <RecordedLine key={index} item={item} currency={currency} />
              ))
            ) : (
              <div className="flex items-start justify-between gap-4 text-[13px]">
                <span className="text-muted-foreground">
                  {t(
                    !invoice
                      ? 'No invoice yet.'
                      : hasRecordedCharges
                        ? 'Recorded charges; detailed pricing unavailable.'
                        : 'Invoice breakdown unavailable.',
                  )}
                </span>
                {hasRecordedCharges && (
                  <span className="font-medium text-foreground">
                    {formatCurrency(charges, currency)}
                  </span>
                )}
              </div>
            )}
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
                      items={organizationLines}
                      currency={currency}
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
              <div className="space-y-3 border-t border-border pt-6 mt-4">
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
                              {project.items.length
                                ? formatCurrency(project.total, currency)
                                : '-'}
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
                                items={project.items}
                                currency={currency}
                                plan={plan}
                                dedicatedDbBillingSpecLookup={
                                  dedicatedDbBillingSpecLookup
                                }
                              />
                            ))}
                          </div>
                          {project.items
                            .filter(
                              (item) =>
                                !project.categories.some((category) =>
                                  category.resources.some(
                                    (resource) =>
                                      resource.resourceId === item.resourceId,
                                  ),
                                ),
                            )
                            .map((item, index) => (
                              <RecordedLine
                                key={index}
                                item={item}
                                currency={currency}
                              />
                            ))}
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

            {creditsApplied > 0 && (
              <div className="flex items-center justify-between text-[13px]">
                <span className="text-muted-foreground">
                  {t('Credits applied')}
                </span>
                <span className="font-medium text-foreground">
                  {formatCurrency(-creditsApplied, currency)}
                </span>
              </div>
            )}
            {hasRecordedCharges && (
              <div className="flex items-center justify-between border-t border-border pt-3 text-[13px]">
                <span className="font-medium text-foreground">
                  {t('Total')}
                </span>
                <span className="font-semibold text-foreground">
                  {formatCurrency(totalAmount, currency)}
                </span>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Footer */}
      {plan?.selfService !== false && (
        <div className="border-t border-border px-6 py-4 bg-muted/30">
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
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
            {showEnterpriseNote && (
              <p className="ms-auto text-[12px] text-muted-foreground">
                <EnterpriseBadge />
                {t('Need a custom SLA or a private Slack channel?')}{' '}
                <ContactSalesLink
                  source="billing"
                  orgId={orgId}
                  className="font-medium text-foreground underline hover:no-underline"
                >
                  {t('Talk to sales')}
                </ContactSalesLink>
              </p>
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
  items,
  currency,
}: {
  items: readonly Models.InvoiceItem[]
  currency: string
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
              items={items}
              currency={currency}
            />
          ))}
          {ungrouped.map((resource) => (
            <RecordedUsageRow
              key={resource.resourceId}
              resource={resource}
              items={items}
              currency={currency}
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
          <RecordedUsageRow
            key={resource.resourceId}
            resource={resource}
            items={items}
            currency={currency}
            onUpgrade={onUpgrade}
          />
        ))}
      </div>
    </div>
  )
}

function BillingDedicatedDbSpecGroup({
  group,
  items,
  currency,
}: {
  items: readonly Models.InvoiceItem[]
  currency: string
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
          {items.some((line) =>
            group.items.some(
              (resource) => resource.resourceId === line.resourceId,
            ),
          )
            ? formatCurrency(group.totalCost, currency)
            : '-'}
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
          <div key={item.resourceId}>
            <BillingDedicatedDbMetricRow
              item={item}
              currency={currency}
              recorded={items.filter(
                (line) => line.resourceId === item.resourceId,
              )}
            />
            {items
              .filter((line) => line.resourceId === item.resourceId)
              .map((line, index) => (
                <div
                  key={index}
                  className="px-3 pb-1 text-[11px] text-muted-foreground"
                >
                  {line.description}
                  <LinePeriod item={line} />
                </div>
              ))}
          </div>
        ))}
      </div>
    </div>
  )
}

function BillingDedicatedDbMetricRow({
  item,
  currency,
  recorded,
}: {
  recorded: readonly Models.InvoiceItem[]
  currency: string
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
          {recorded.length
            ? formatCurrency(
                recorded.reduce((sum, line) => sum + line.amount, 0) / 100,
                currency,
              )
            : '-'}
        </span>
      </div>
    </div>
  )
}

function BillingProjectResourceRow({
  resource,
  onUpgrade,
  currency,
}: {
  currency: string
  resource: BillingProjectResourceItem
  onUpgrade?: () => void
}) {
  const t = useT()
  const usage = toByteCount(resource.usage)
  const limit = resource.limit == null ? null : toByteCount(resource.limit)
  const usagePercentage =
    limit && limit > 0 ? Math.min(100, (usage / limit) * 100) : null
  const usageFormatted =
    resource.usageLabel ?? formatResourceUsage(usage, resource.formatType)
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
          {resource.resourceId === 'storage' &&
          resource.storageBreakdown &&
          resource.storageBreakdown.length > 0 &&
          usagePercentage !== null ? (
            <BillingStorageUsageProgress
              totalUsageBytes={usage}
              usagePercentage={usagePercentage}
              segments={resource.storageBreakdown}
              highlightWhenHigh
            />
          ) : usagePercentage !== null ? (
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
          {resource.cost !== 0 ? formatCurrency(resource.cost, currency) : null}
        </span>
      </div>
    </div>
  )
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
    return formatDecimalBytes(value)
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
    return formatDecimalBytes(value)
  }
  if (type === 'sms') {
    return `${value.toLocaleString()}`
  }
  return formatNumber(value)
}

function usageCategories(
  resources: AggregationResourceLike[] | null | undefined,
  items: readonly Models.InvoiceItem[],
  plan: Models.BillingPlan | null | undefined,
  specifications: ReturnType<typeof buildDedicatedDbBillingSpecLookup>,
): BillingProjectResourceCategoryGroup[] {
  const usage = resources ?? []
  const ids = [
    ...new Set([
      ...(resources ? Object.keys(getBillingProjectResourceIdMap(plan)) : []),
      ...usage.flatMap((resource) =>
        resource.resourceId ? [resource.resourceId] : [],
      ),
      ...items.flatMap((item) => (item.resourceId ? [item.resourceId] : [])),
    ]),
  ]
  const rows: BillingProjectResourceItem[] = []
  for (const id of ids) {
    const resource = usage.find((resource) => resource.resourceId === id)
    const recorded = items.filter((item) => item.resourceId === id)
    const mapping =
      resolveBillingProjectResourceMapping(id, plan, specifications) ??
      (id.startsWith('addon_')
        ? {
            name: recorded[0]?.description ?? resource?.name ?? id,
            format: 'number' as const,
            planKey: id,
            category: 'addons' as const,
            showLimit: false,
          }
        : null)
    if (!mapping) continue
    const value = Number(
      resource?.value ??
        recorded.find((item) => item.quantity != null)?.quantity ??
        0,
    )
    if (mapping.showOnlyWhenUsed && value === 0 && recorded.length === 0)
      continue
    const unavailable = isBillingPlanResourceUnavailable(plan, mapping.planKey)
    const limit = getBillingPlanResourceLimit(plan, mapping.planKey)
    if (!resource && recorded.length === 0 && limit === null && !unavailable)
      continue
    rows.push({
      resourceId: id,
      name: mapping.name,
      usage: value,
      limit,
      // Aggregation amounts and plan prices never enter the displayed charges.
      cost: recorded.reduce((sum, item) => sum + item.amount, 0) / 100,
      formatType: mapping.format,
      category: mapping.category,
      showLimit: !unavailable && mapping.showLimit !== false,
      requiresUpgrade: unavailable,
      ...(id === 'storage'
        ? { storageBreakdown: getBillingStorageBreakdownFromResources(usage) }
        : {}),
    })
  }
  return groupBillingProjectResources(rows)
}

function LinePeriod({ item }: { item: Models.InvoiceItem }) {
  if (!item.from && !item.to) return null
  return (
    <p className="text-[11px] text-muted-foreground py-1">
      {item.from && <DateTooltip date={item.from} showFormattedDate />}
      {item.from && item.to && ' - '}
      {item.to && <DateTooltip date={item.to} showFormattedDate />}
    </p>
  )
}

function RecordedLine({
  item,
  currency,
}: {
  item: Models.InvoiceItem
  currency: string
}) {
  return (
    <div className="py-1.5 text-[13px]">
      <div className="flex justify-between gap-4">
        <span className="flex items-center gap-2">
          {item.description}
          {item.type === 'addon' &&
            (item.resourceId === 'member' || item.resourceId === 'projects') &&
            item.quantity != null && (
              <Badge
                variant="info"
                className="h-4 px-1.5 text-[10px] font-medium shrink-0"
              >
                {item.quantity}
              </Badge>
            )}
        </span>
        <span
          className={cn(
            'font-medium',
            item.amount < 0 && 'text-green-600 dark:text-green-400',
          )}
        >
          {formatCurrency(item.amount / 100, currency)}
        </span>
      </div>
      <LinePeriod item={item} />
    </div>
  )
}

function RecordedUsageRow({
  resource,
  items,
  currency,
  onUpgrade,
}: {
  resource: BillingProjectResourceItem
  items: readonly Models.InvoiceItem[]
  currency: string
  onUpgrade?: () => void
}) {
  const recorded = items.filter(
    (item) => item.resourceId === resource.resourceId,
  )
  if (recorded.length === 0)
    return (
      <BillingProjectResourceRow
        resource={{ ...resource, cost: 0 }}
        currency={currency}
        onUpgrade={onUpgrade}
      />
    )
  return recorded.map((item, index) => (
    <div key={index}>
      <BillingProjectResourceRow
        resource={{
          ...resource,
          name: item.description,
          cost: item.amount / 100,
        }}
        currency={currency}
        onUpgrade={onUpgrade}
      />
      <LinePeriod item={item} />
    </div>
  ))
}
