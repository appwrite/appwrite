import { useState, useMemo, useEffect, useRef } from 'react'
import { Link, Outlet, useLocation } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { refetchProjectBandwidthUsageQueries, refetchProjectDatabaseUsageQueries, refetchProjectAuthUsageQueries, refetchProjectComputeUsageQueries, refetchProjectRealtimeUsageQueries, refetchProjectRequestsUsageQueries } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
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
  RefreshCw,
  AlertTriangle,
  ChevronRight,
  Menu,
  type LucideIcon,
} from '@/lib/icons'
import { Button } from '@/components/ui/button'
import { ScrollArea } from '@/components/ui/scroll-area'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Skeleton } from '@/components/ui/skeleton'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  type UsageCategory,
  type UsageState,
  getUsagePercentage,
  getUsageStatus,
} from './data'
import { DateRangePicker } from '../analytics/DateRangePicker'
import { UsageHistoricDataNote } from '../shared/UsageHistoricDataNote'
import { UsageChartIntervalToggle } from '../overview/UsageChartIntervalToggle'
import { categorySupportsChartInterval } from './category-filter-state'
import { useUsageChartFilters } from '@/hooks/use-usage-chart-filters'
import {
  findUsageCategory,
  getUsageCategories,
  getUsageCategoryIdFromPathname,
  getUsageNavGroups,
  resolveUsageCategoryId,
  type UsageNavGroup,
} from './usage-nav'
import { UsageFiltersProvider } from './usage-filters-context'
import {
  RefreshProvider,
  useRefresh,
} from '@/components/global/shared/RefreshContext'

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
      className={cn(
        'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-[13px] font-medium transition-colors',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
        isActive
          ? 'bg-accent text-foreground'
          : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
      )}
    >
      <div className="relative">
        <Icon className="h-4 w-4 shrink-0" />
        {hasWarning ? (
          <span className="absolute -right-1 -top-1 h-2 w-2 rounded-full bg-amber-500" />
        ) : null}
      </div>
      <span className="truncate">{category.label}</span>
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
  return (
    <p className="mb-1.5 px-2.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground/60">
      {label}
    </p>
  )
}

function CategoryNavigation({
  projectId,
  navGroups,
  activeCategoryId,
  className,
}: CategoryNavigationProps) {
  return (
    <nav
      className={cn('flex flex-col gap-5', className)}
      role="navigation"
      aria-label="Usage categories"
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
  const activeLabel = activeCategory?.label ?? 'Select category'

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
          <SheetTitle className="text-left text-[15px]">
            Usage Categories
          </SheetTitle>
        </SheetHeader>
        <ScrollArea className="h-[calc(100dvh-65px)]">
          <div className="px-3 py-4">
            <CategoryNavigation
              projectId={projectId}
              navGroups={navGroups}
              activeCategoryId={activeCategoryId}
            />
          </div>
        </ScrollArea>
      </SheetContent>
    </Sheet>
  )
}

function UsageLoadingState() {
  return (
    <div className="flex h-full">
      <div className="hidden w-[220px] shrink-0 border-r border-border px-3 py-4 lg:block">
        <div className="space-y-5">
          {Array.from({ length: 2 }).map((_, groupIndex) => (
            <div key={groupIndex} className="space-y-0.5">
              <Skeleton className="mb-1.5 h-3 w-16" />
              {Array.from({ length: groupIndex === 0 ? 3 : 5 }).map((__, i) => (
                <Skeleton key={i} className="h-9 w-full rounded-md" />
              ))}
            </div>
          ))}
        </div>
      </div>
      <div className="flex-1 p-6">
        <Skeleton className="mb-6 h-8 w-48" />
        <div className="grid gap-6 lg:grid-cols-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-[320px] w-full rounded-lg" />
          ))}
        </div>
      </div>
    </div>
  )
}

interface UsageErrorStateProps {
  onRetry: () => void
}

function UsageErrorState({ onRetry }: UsageErrorStateProps) {
  return (
    <div className="flex h-full items-center justify-center p-6">
      <div className="max-w-md text-center">
        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-destructive/10">
          <AlertTriangle className="h-6 w-6 text-destructive" />
        </div>
        <h2 className="mb-2 text-[16px] font-semibold text-foreground">
          Failed to load usage data
        </h2>
        <p className="mb-4 text-[13px] text-muted-foreground">
          We couldn't retrieve your usage metrics. This might be a temporary
          issue. Please try again.
        </p>
        <Button onClick={onRetry} variant="outline" className="gap-2">
          <RefreshCw className="h-4 w-4" />
          Retry
        </Button>
      </div>
    </div>
  )
}

function UsageEmptyState() {
  return (
    <EmptyState
      icon={Database}
      title="No usage data available"
      description="Usage metrics will appear here once your project starts receiving traffic. Deploy your first function or create some data to get started."
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
  const location = useLocation()
  const categoryId = resolveUsageCategoryId(
    getUsageCategoryIdFromPathname(location.pathname),
    plan,
  )

  const {
    dateRange: usageDateRange,
    chartInterval,
    setDateRange: setUsageDateRange,
    setChartInterval,
  } = useUsageChartFilters()

  const [state, setState] = useState<UsageState>('success')
  const contentScrollRef = useRef<HTMLDivElement>(null)
  const queryClient = useQueryClient()
  const {
    triggerRefresh,
    hasRefreshHandler,
    isRefreshing,
  } = useRefresh()

  const categories = useMemo(() => getUsageCategories(plan), [plan])
  const navGroups = useMemo(() => getUsageNavGroups(plan), [plan])
  const activeCategoryData = findUsageCategory(categories, categoryId)
  const isRequestsCategory = categoryId === 'requests'
  const isBandwidthCategory = categoryId === 'bandwidth'
  const isComputeCategory = categoryId === 'compute'
  const isDatabasesCategory = categoryId === 'databases'
  const isRealtimeCategory = categoryId === 'realtime'
  const isAuthCategory = categoryId === 'auth'
  const showChartIntervalToggle = categorySupportsChartInterval(categoryId)

  useEffect(() => {
    contentScrollRef.current?.scrollTo({ top: 0 })
  }, [categoryId])

  const handleRefresh = () => {
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

    if (isComputeCategory) {
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
    <div className={cn('flex h-full overflow-hidden', className)}>
      <aside className="hidden w-[220px] shrink-0 border-r border-border lg:block">
        <div className="h-full px-3 py-4">
          <CategoryNavigation
            projectId={projectId}
            navGroups={navGroups}
            activeCategoryId={categoryId}
          />
        </div>
      </aside>

      <div className="flex flex-1 flex-col overflow-hidden">
        <div className="border-b border-border">
          <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1">
                <h1 className="text-[17px] font-semibold text-foreground">
                  Usage
                </h1>
                <UsageHistoricDataNote className="min-w-0" />
              </div>

              <div className="flex flex-wrap items-center justify-end gap-3">
                {showChartIntervalToggle ? (
                  <UsageChartIntervalToggle
                    value={chartInterval}
                    onValueChange={setChartInterval}
                    dateRange={usageDateRange}
                    className="h-9"
                  />
                ) : null}
                <DateRangePicker
                  dateRange={usageDateRange}
                  onDateRangeChange={setUsageDateRange}
                  className="h-9"
                />

                <TooltipProvider delayDuration={0}>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={handleRefresh}
                        disabled={isRefreshing}
                        className="h-9 w-9 p-0"
                      >
                        <RefreshCw
                          className={cn(
                            'h-4 w-4',
                            isRefreshing && 'animate-spin',
                          )}
                        />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent>Refresh</TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              </div>
            </div>
          </div>
        </div>

        <div ref={contentScrollRef} className="flex-1 overflow-y-auto">
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
                    {activeCategoryData.label}
                  </h2>
                  <p className="mt-1 text-[13px] text-muted-foreground">
                    {activeCategoryData.description}
                  </p>
                </div>

                <UsageFiltersProvider
                  value={{
                    plan,
                    dateRange: usageDateRange,
                    chartInterval,
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
