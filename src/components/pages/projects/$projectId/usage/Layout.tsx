import { useState, useMemo, useEffect, useRef, useCallback } from 'react'
import { Link, Outlet, useLocation, useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import {
  refetchProjectBandwidthUsageQueries,
  refetchProjectDatabaseUsageQueries,
  refetchProjectAuthUsageQueries,
  refetchProjectAvatarsUsageQueries,
  refetchProjectMessagingUsageQueries,
  refetchProjectWebhooksUsageQueries,
  refetchProjectComputeUsageQueries,
  refetchProjectRealtimeUsageQueries,
  refetchProjectRequestsUsageQueries,
  refetchProjectStorageUsageQueries,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import {
  Zap,
  Users,
  Database,
  Folder,
  ArrowUpDown,
  Radio,
  MessageSquare,
  Activity,
  Cpu,
  UserCircle,
  Webhook,
  RefreshCw,
  AlertTriangle,
  ChevronRight,
  Menu,
  Globe,
  Shield,
  type LucideIcon,
} from '@/lib/icons'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  type UsageCategory,
  type UsageState,
  getUsagePercentage,
  getUsageStatus,
} from './data'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { UsageHistoricDataNote } from '../shared/UsageHistoricDataNote'
import { UsageChartIntervalToggle } from '../overview/UsageChartIntervalToggle'
import { categorySupportsChartInterval } from './category-filter-state'
import { useUsageChartFilters } from '@/hooks/use-usage-chart-filters'
import { useUsageHistoryLimitAlertState } from '@/hooks/use-usage-history-limit-alert'
import {
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canWriteRules } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  getUsageLogRetentionDaysFromPlan,
  getUsageLogRetentionHoursFromPlan,
  hasFiniteUsageLogRetention,
  resolveShorterUsageDateRangePreset,
} from '@/lib/usage/usage-log-retention'
import { getUsageChartIntervalsForPlan } from '@/lib/usage/chart-interval'
import { UsageLogRetentionAlert } from './_components/UsageLogRetentionAlert'
import {
  findUsageCategory,
  getUsageCategories,
  getUsageCategoryIdFromPathname,
  getUsageNavGroups,
  resolveUsageCategoryId,
  type UsageNavGroup,
} from './usage-nav'
import { UsageFiltersProvider } from './usage-filters-context'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import {
  buildFilterQueryString,
  mapToQueryParam,
  queryParamToMap,
  type CompactFilterKey,
} from '@/lib/table-filters'
import {
  categorySupportsUsageFilters,
  getUsageFilterColumnsForCategory,
  getUsageSavedFilterScope,
  isUsageFilterDimensionAllowed,
  USAGE_FILTER_EXCLUDED_ATTRIBUTES,
} from '@/lib/usage/usage-filter-configs'
import {
  getUsageFilterQueriesForSurface,
  sanitizeUsageFilterMap,
} from '@/lib/usage/usage-filter-queries'
import { canApplyUsageFiltersAsFirewallRule } from '@/lib/firewall/usage'
import type { UsageBreakdownFilterEntry } from '@/lib/usage/usage-resource-filters'
import {
  RefreshProvider,
  useRefresh,
} from '@/components/global/shared/RefreshContext'
import {
  SECONDARY_SIDEBAR_ASIDE_CLASS,
  SECONDARY_SIDEBAR_CONTENT_CLASS,
  SECONDARY_SIDEBAR_GROUP_HEADING_CLASS,
  SECONDARY_SIDEBAR_LAYOUT_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS,
  secondarySidebarNavLinkClassName,
} from '@/lib/layout/secondary-sidebar-nav'
import { usePageDirection } from '@/lib/layout/page-direction'

const iconMap: Record<string, LucideIcon> = {
  Zap,
  Users,
  Database,
  Folder,
  ArrowUpDown,
  Radio,
  MessageSquare,
  Activity,
  Cpu,
  UserCircle,
  Webhook,
  Globe,
}

interface UsageLayoutProps {
  projectId: string
  plan?: 'free' | 'pro' | 'custom'
  className?: string
}

interface UsageCategoryNavLinkProps {
  projectId: string
  category: UsageCategory
  isActive: boolean
}

function UsageCategoryNavLink({
  projectId,
  category,
  isActive,
}: UsageCategoryNavLinkProps) {
  const t = useT()
  const Icon = iconMap[category.icon] || Zap
  const hasWarning = category.metrics.some((metric) => {
    const percentage = getUsagePercentage(metric.currentValue, metric.quota)
    const status = getUsageStatus(percentage)
    return status === 'warning' || status === 'critical'
  })

  return (
    <Link
      to="/projects/$projectId/usage/$categoryId"
      params={{ projectId, categoryId: category.id }}
      search={{ query: undefined }}
      className={cn(
        secondarySidebarNavLinkClassName(isActive),
        SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
      )}
    >
      <div className="relative shrink-0">
        <Icon className="h-4 w-4 shrink-0" />
        {hasWarning ? (
          <span className="absolute -end-1 -top-1 h-2 w-2 rounded-full bg-amber-500" />
        ) : null}
      </div>
      <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>
        {t(category.label)}
      </span>
    </Link>
  )
}

interface CategoryNavigationProps {
  projectId: string
  navGroups: UsageNavGroup[]
  activeCategoryId: string
  className?: string
}

function UsageNavGroupHeading({ label }: { label: string }) {
  const t = useT()
  return <p className={SECONDARY_SIDEBAR_GROUP_HEADING_CLASS}>{t(label)}</p>
}

function CategoryNavigation({
  projectId,
  navGroups,
  activeCategoryId,
  className,
}: CategoryNavigationProps) {
  const t = useT()
  return (
    <nav
      className={cn('flex flex-col gap-5', className)}
      role="navigation"
      aria-label={t('Usage categories')}
    >
      {navGroups.map((group) => (
        <div key={group.id} className="space-y-0.5">
          <UsageNavGroupHeading label={group.label} />
          <div className="flex flex-col gap-0.5">
            {group.categories.map((category) => (
              <UsageCategoryNavLink
                key={category.id}
                projectId={projectId}
                category={category}
                isActive={activeCategoryId === category.id}
              />
            ))}
          </div>
        </div>
      ))}
    </nav>
  )
}

interface MobileCategoryDrawerProps {
  projectId: string
  navGroups: UsageNavGroup[]
  activeCategoryId: string
}

function MobileCategoryDrawer({
  projectId,
  navGroups,
  activeCategoryId,
}: MobileCategoryDrawerProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const location = useLocation()
  const categories = useMemo(
    () => navGroups.flatMap((group) => group.categories),
    [navGroups],
  )

  useEffect(() => {
    setOpen(false)
  }, [location.pathname])

  const activeCategory = categories.find((c) => c.id === activeCategoryId)
  const activeLabel = activeCategory?.label
    ? t(activeCategory.label)
    : t('Select category')

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button
          variant="outline"
          className="w-full justify-between gap-2 lg:hidden"
        >
          <span className="flex items-center gap-2">
            <Menu className="h-4 w-4" />
            {activeLabel}
          </span>
          <ChevronRight className="h-4 w-4" />
        </Button>
      </SheetTrigger>
      <SheetContent side="left" className="w-[280px] p-0">
        <SheetHeader className="border-b border-border px-4 py-4">
          <SheetTitle className="text-start text-[15px]">
            {t('Usage Categories')}
          </SheetTitle>
        </SheetHeader>
        <div className="h-[calc(100dvh-65px)] overflow-y-auto overscroll-contain">
          <div className="px-3 py-4">
            <CategoryNavigation
              projectId={projectId}
              navGroups={navGroups}
              activeCategoryId={activeCategoryId}
            />
          </div>
        </div>
      </SheetContent>
    </Sheet>
  )
}

function UsageLoadingState() {
  return (
    <div className="flex h-full flex-col overflow-hidden">
      <div className="shrink-0 border-b border-border px-4 py-4 sm:px-6">
        <Skeleton className="h-9 w-full max-w-xl" />
      </div>
      <div className={SECONDARY_SIDEBAR_LAYOUT_CLASS}>
        <div className={cn(SECONDARY_SIDEBAR_ASIDE_CLASS, 'px-3 py-4')}>
          <div className="space-y-5">
            {Array.from({ length: 2 }).map((_, groupIndex) => (
              <div key={groupIndex} className="space-y-0.5">
                <Skeleton className="mb-1.5 h-3 w-16" />
                {Array.from({ length: groupIndex === 0 ? 4 : 5 }).map(
                  (__, i) => (
                    <Skeleton key={i} className="h-9 w-full rounded-md" />
                  ),
                )}
              </div>
            ))}
          </div>
        </div>
        <div className={cn(SECONDARY_SIDEBAR_CONTENT_CLASS, 'p-6')}>
          <Skeleton className="mb-6 h-8 w-48" />
          <div className="grid gap-6 lg:grid-cols-2">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-[320px] w-full rounded-lg" />
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}

interface UsageErrorStateProps {
  onRetry: () => void
}

function UsageErrorState({ onRetry }: UsageErrorStateProps) {
  const t = useT()
  return (
    <div className="flex h-full items-center justify-center p-6">
      <div className="max-w-md text-center">
        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-destructive/10">
          <AlertTriangle className="h-6 w-6 text-destructive" />
        </div>
        <h2 className="mb-2 text-[16px] font-semibold text-foreground">
          {t('Failed to load usage data')}
        </h2>
        <p className="mb-4 text-[13px] text-muted-foreground">
          {t(
            "We couldn't retrieve your usage metrics. This might be a temporary issue. Please try again.",
          )}
        </p>
        <Button onClick={onRetry} variant="outline" className="gap-2">
          <RefreshCw className="h-4 w-4" />
          {t('Retry')}
        </Button>
      </div>
    </div>
  )
}

function UsageEmptyState() {
  const t = useT()
  return (
    <EmptyState
      icon={Database}
      title={t('No usage data available')}
      description={t(
        'Usage metrics will appear here once your project starts receiving traffic. Deploy your first function or create some data to get started.',
      )}
      isEmpty={true}
      variant="centered"
    />
  )
}

export function UsageLayout({
  projectId,
  plan = 'pro',
  className,
}: UsageLayoutProps) {
  return (
    <RefreshProvider>
      <UsageLayoutContent
        projectId={projectId}
        plan={plan}
        className={className}
      />
    </RefreshProvider>
  )
}

function UsageLayoutContent({
  projectId,
  plan = 'pro',
  className,
}: UsageLayoutProps) {
  const t = useT()
  const location = useLocation()
  const navigate = useNavigate()
  const categoryId = resolveUsageCategoryId(
    getUsageCategoryIdFromPathname(location.pathname),
    plan,
  )
  const [filtersOpen, setFiltersOpen] = useState(false)
  const { features, isSelfHosted } = useConsoleProfile()
  const filterAvailability = useMemo(
    () => ({ allowCity: !isSelfHosted }),
    [isSelfHosted],
  )

  const usageQueryParam = useMemo(() => {
    const search = location.search
    return typeof search === 'object' && search !== null && 'query' in search
      ? ((search as { query?: string }).query ?? null)
      : null
  }, [location.search])
  const usageFilterMap = useMemo(
    () =>
      sanitizeUsageFilterMap(
        queryParamToMap(usageQueryParam),
        categoryId,
        filterAvailability,
      ),
    [usageQueryParam, categoryId, filterAvailability],
  )

  const usageFilterColumns = useMemo(
    () => getUsageFilterColumnsForCategory(categoryId, filterAvailability),
    [categoryId, filterAvailability],
  )
  const usageEventFilterQueries = useMemo(
    () =>
      getUsageFilterQueriesForSurface(
        usageFilterMap,
        categoryId,
        'events',
        filterAvailability,
      ),
    [usageFilterMap, categoryId, filterAvailability],
  )
  const usageGaugeFilterQueries = useMemo(
    () =>
      getUsageFilterQueriesForSurface(
        usageFilterMap,
        categoryId,
        'gauges',
        filterAvailability,
      ),
    [usageFilterMap, categoryId, filterAvailability],
  )
  const usageFilterScope = getUsageSavedFilterScope(categoryId)
  const showUsageFilters = categorySupportsUsageFilters(categoryId)

  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { access } = useOrganizationScopes(project?.teamId)
  const canWriteFirewallRules = canWriteRules(access, features)
  const canApplyFiltersAsFirewallRule =
    features.firewall &&
    showUsageFilters &&
    canApplyUsageFiltersAsFirewallRule(usageFilterMap)

  const navigateUsageFilters = useCallback(
    (query: string | undefined) => {
      navigate({
        to: '/projects/$projectId/usage/$categoryId',
        params: { projectId, categoryId },
        search: query ? { query } : { query: undefined },
        replace: true,
      })
    },
    [navigate, projectId, categoryId],
  )

  useEffect(() => {
    const sanitizedQuery =
      usageFilterMap.size > 0 ? mapToQueryParam(usageFilterMap) : undefined
    if (sanitizedQuery !== (usageQueryParam ?? undefined)) {
      navigateUsageFilters(sanitizedQuery)
    }
  }, [navigateUsageFilters, usageFilterMap, usageQueryParam])

  const applyUsageFilter = useCallback(
    (
      key: CompactFilterKey,
      queryStr: string,
      replaceKey?: CompactFilterKey,
    ) => {
      if (USAGE_FILTER_EXCLUDED_ATTRIBUTES.has(String(key.c))) return
      if (
        !isUsageFilterDimensionAllowed(
          categoryId,
          String(key.c),
          filterAvailability,
        )
      )
        return
      const newMap = new Map(usageFilterMap)
      if (replaceKey) newMap.delete(replaceKey)
      newMap.set(key, queryStr)
      navigateUsageFilters(
        mapToQueryParam(
          sanitizeUsageFilterMap(newMap, categoryId, filterAvailability),
        ),
      )
    },
    [usageFilterMap, navigateUsageFilters, categoryId, filterAvailability],
  )

  const removeUsageFilter = useCallback(
    (key: CompactFilterKey) => {
      const newMap = new Map(usageFilterMap)
      newMap.delete(key)
      navigateUsageFilters(
        newMap.size > 0 ? mapToQueryParam(newMap) : undefined,
      )
    },
    [usageFilterMap, navigateUsageFilters],
  )

  const clearAllUsageFilters = useCallback(() => {
    navigateUsageFilters(undefined)
  }, [navigateUsageFilters])

  const applySavedUsageFilterQuery = useCallback(
    (queryParam: string | undefined) => {
      if (!queryParam) {
        navigateUsageFilters(undefined)
        return
      }
      const sanitizedMap = sanitizeUsageFilterMap(
        queryParamToMap(queryParam),
        categoryId,
        filterAvailability,
      )
      navigateUsageFilters(
        sanitizedMap.size > 0 ? mapToQueryParam(sanitizedMap) : undefined,
      )
    },
    [navigateUsageFilters, categoryId, filterAvailability],
  )

  const addBreakdownUsageFilter = useCallback(
    (filters: UsageBreakdownFilterEntry[]) => {
      if (filters.length === 0) return

      const newMap = new Map(usageFilterMap)
      for (const { dimension, value } of filters) {
        if (USAGE_FILTER_EXCLUDED_ATTRIBUTES.has(dimension)) continue
        if (
          !isUsageFilterDimensionAllowed(
            categoryId,
            dimension,
            filterAvailability,
          )
        )
          continue
        const trimmed = value.trim()
        if (!trimmed) continue
        const key: CompactFilterKey = { c: dimension, o: 'equal', v: trimmed }
        newMap.set(key, buildFilterQueryString('equal', dimension, trimmed))
      }

      navigateUsageFilters(
        mapToQueryParam(
          sanitizeUsageFilterMap(newMap, categoryId, filterAvailability),
        ),
      )
    },
    [usageFilterMap, navigateUsageFilters, categoryId, filterAvailability],
  )

  const applyFiltersAsFirewallRule = useCallback(() => {
    if (!canApplyFiltersAsFirewallRule || !canWriteFirewallRules) return
    navigate({
      to: '/projects/$projectId/firewall/create',
      params: { projectId },
      search: {
        query: mapToQueryParam(usageFilterMap),
      },
    })
  }, [
    canApplyFiltersAsFirewallRule,
    canWriteFirewallRules,
    navigate,
    projectId,
    usageFilterMap,
  ])

  const {
    dateRange: usageDateRange,
    chartInterval,
    dateRangePresetId,
    setDateRange: setUsageDateRange,
    setChartInterval,
    refreshRollingDateRange,
  } = useUsageChartFilters(organizationPlan)
  const usageLogRetentionHours = useMemo(
    () => getUsageLogRetentionHoursFromPlan(organizationPlan),
    [organizationPlan],
  )
  const usageLogRetentionDays = useMemo(
    () => getUsageLogRetentionDaysFromPlan(organizationPlan),
    [organizationPlan],
  )
  const planChartIntervals = useMemo(
    () => getUsageChartIntervalsForPlan(organizationPlan),
    [organizationPlan],
  )

  const { showAlert: showUsageHistoryLimitAlert } =
    useUsageHistoryLimitAlertState({
      projectId,
      dateRange: usageDateRange,
      dateRangePresetId,
      retentionHours: usageLogRetentionHours,
      organizationPlan,
    })

  const handleAdjustUsageDateRange = useCallback(() => {
    const fallbackPreset = resolveShorterUsageDateRangePreset(
      usageLogRetentionHours,
    )
    if (fallbackPreset) {
      setUsageDateRange(fallbackPreset.getRange())
    }
  }, [setUsageDateRange, usageLogRetentionHours])

  const [state] = useState<UsageState>('success')
  const contentScrollRef = useRef<HTMLDivElement>(null)
  const queryClient = useQueryClient()
  const pageDirection = usePageDirection()
  const { triggerRefresh, hasRefreshHandler, isRefreshing } = useRefresh()

  const categories = useMemo(() => getUsageCategories(plan), [plan])
  const navGroups = useMemo(() => getUsageNavGroups(plan), [plan])
  const activeCategoryData = findUsageCategory(categories, categoryId)
  const isRequestsCategory = categoryId === 'requests'
  const isBandwidthCategory = categoryId === 'bandwidth'
  const isComputeCategory = categoryId === 'compute'
  const isFunctionsCategory = categoryId === 'functions'
  const isSitesCategory = categoryId === 'sites'
  const isDatabasesCategory = categoryId === 'databases'
  const isRealtimeCategory = categoryId === 'realtime'
  const isAuthCategory = categoryId === 'auth'
  const isAvatarsCategory = categoryId === 'avatars'
  const isMessagingCategory = categoryId === 'messaging'
  const isWebhooksCategory = categoryId === 'webhooks'
  const isStorageCategory = categoryId === 'storage'
  const showChartIntervalToggle = categorySupportsChartInterval(categoryId)

  useEffect(() => {
    contentScrollRef.current?.scrollTo({ top: 0 })
  }, [categoryId])

  const handleRefresh = () => {
    refreshRollingDateRange()

    if (hasRefreshHandler) {
      void triggerRefresh()
      return
    }

    if (isRequestsCategory) {
      void refetchProjectRequestsUsageQueries(queryClient, projectId)
      return
    }

    if (isBandwidthCategory) {
      void refetchProjectBandwidthUsageQueries(queryClient, projectId)
      return
    }

    if (isComputeCategory || isFunctionsCategory || isSitesCategory) {
      void refetchProjectComputeUsageQueries(queryClient, projectId)
      return
    }

    if (isDatabasesCategory) {
      void refetchProjectDatabaseUsageQueries(queryClient, projectId)
      return
    }

    if (isRealtimeCategory) {
      void refetchProjectRealtimeUsageQueries(queryClient, projectId)
      return
    }

    if (isAuthCategory) {
      void refetchProjectAuthUsageQueries(queryClient, projectId)
      return
    }

    if (isAvatarsCategory) {
      void refetchProjectAvatarsUsageQueries(queryClient, projectId)
      return
    }

    if (isMessagingCategory) {
      void refetchProjectMessagingUsageQueries(queryClient, projectId)
      return
    }

    if (isWebhooksCategory) {
      void refetchProjectWebhooksUsageQueries(queryClient, projectId)
      return
    }

    if (isStorageCategory) {
      void refetchProjectStorageUsageQueries(queryClient, projectId)
    }
  }

  if (state === 'loading') {
    return <UsageLoadingState />
  }

  if (state === 'error') {
    return <UsageErrorState onRetry={handleRefresh} />
  }

  if (state === 'empty') {
    return <UsageEmptyState />
  }

  return (
    <div
      dir={pageDirection}
      className={cn('flex h-full flex-col overflow-hidden', className)}
    >
      <div className="sticky top-0 z-20 shrink-0 bg-background">
        <div className="border-b border-border">
          <div className="w-full px-4 py-4 sm:px-6">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1">
                <h1 className="text-[17px] font-semibold leading-tight text-foreground">
                  {t('Usage')}
                </h1>
                <UsageHistoricDataNote className="min-w-0" />
              </div>

              <div className="flex flex-wrap items-center justify-end gap-3">
                {showUsageFilters ? (
                  <FiltersPopover
                    open={filtersOpen}
                    onOpenChange={setFiltersOpen}
                    columns={usageFilterColumns}
                    filterMap={usageFilterMap}
                    onRemoveFilter={removeUsageFilter}
                    onClearAll={clearAllUsageFilters}
                    onApplyFilter={applyUsageFilter}
                    resourceLabel="usage metrics"
                    filterScope={usageFilterScope}
                    onApplyQuery={applySavedUsageFilterQuery}
                  />
                ) : null}
                {canApplyFiltersAsFirewallRule ? (
                  canWriteFirewallRules ? (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-9 border-border bg-transparent text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground"
                      onClick={applyFiltersAsFirewallRule}
                    >
                      <Shield className="mr-1.5 h-3.5 w-3.5 shrink-0" />
                      {t('Apply as firewall rule')}
                    </Button>
                  ) : (
                    <TooltipProvider delayDuration={0}>
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <div>
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              disabled
                              className="h-9 border-border bg-transparent text-[13px] text-muted-foreground"
                            >
                              <Shield className="mr-1.5 h-3.5 w-3.5 shrink-0" />
                              {t('Apply as firewall rule')}
                            </Button>
                          </div>
                        </TooltipTrigger>
                        <TooltipContent side="bottom">
                          <p>
                            {t(
                              "You don't have permission to create firewall rules.",
                            )}
                          </p>
                        </TooltipContent>
                      </Tooltip>
                    </TooltipProvider>
                  )
                ) : null}
                {showChartIntervalToggle ? (
                  <UsageChartIntervalToggle
                    value={chartInterval}
                    onValueChange={setChartInterval}
                    dateRange={usageDateRange}
                    allowedIntervals={planChartIntervals}
                    className="h-9"
                  />
                ) : null}
                <DateRangePicker
                  dateRange={usageDateRange}
                  onDateRangeChange={setUsageDateRange}
                  presetId={dateRangePresetId}
                  className="h-9"
                />

                <RefreshButton
                  onClick={handleRefresh}
                  isRefreshing={isRefreshing}
                />
              </div>
            </div>
          </div>
        </div>
        {showUsageHistoryLimitAlert &&
        hasFiniteUsageLogRetention(organizationPlan) ? (
          <UsageLogRetentionAlert
            retentionDays={usageLogRetentionDays}
            organizationId={project?.teamId}
            onAdjustRange={handleAdjustUsageDateRange}
          />
        ) : null}
      </div>

      <div className={SECONDARY_SIDEBAR_LAYOUT_CLASS}>
        <aside
          className={cn(SECONDARY_SIDEBAR_ASIDE_CLASS, 'min-h-0 self-stretch')}
          dir={pageDirection}
        >
          <div className="h-full overflow-y-auto overscroll-contain">
            <div className="px-3 py-4">
              <CategoryNavigation
                projectId={projectId}
                navGroups={navGroups}
                activeCategoryId={categoryId}
              />
            </div>
          </div>
        </aside>

        <div ref={contentScrollRef} className={SECONDARY_SIDEBAR_CONTENT_CLASS}>
          <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
            <div className="mb-6 lg:hidden">
              <MobileCategoryDrawer
                projectId={projectId}
                navGroups={navGroups}
                activeCategoryId={categoryId}
              />
            </div>

            {activeCategoryData ? (
              <>
                <div className="mb-6">
                  <h2 className="text-[16px] font-semibold text-foreground">
                    {t(activeCategoryData.label)}
                  </h2>
                  <p className="mt-1 text-[13px] text-muted-foreground">
                    {t(activeCategoryData.description)}
                  </p>
                </div>

                <UsageFiltersProvider
                  value={{
                    plan,
                    organizationId: project?.teamId,
                    usageLogRetentionHours,
                    usageLogRetentionDays,
                    dateRange: usageDateRange,
                    chartInterval,
                    onDateRangeChange: setUsageDateRange,
                    filterMap: usageFilterMap,
                    eventFilterQueries: usageEventFilterQueries,
                    gaugeFilterQueries: usageGaugeFilterQueries,
                    filterColumns: usageFilterColumns,
                    filterScope: usageFilterScope,
                    onApplyFilter: applyUsageFilter,
                    onRemoveFilter: removeUsageFilter,
                    onClearAllFilters: clearAllUsageFilters,
                    onApplySavedFilterQuery: applySavedUsageFilterQuery,
                    onAddBreakdownFilter: addBreakdownUsageFilter,
                  }}
                >
                  <Outlet />
                </UsageFiltersProvider>
              </>
            ) : null}
          </div>
        </div>
      </div>
    </div>
  )
}
