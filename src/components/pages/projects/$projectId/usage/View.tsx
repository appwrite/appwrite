import { useState, useMemo } from 'react'
import { cn } from '@/lib/utils'
import {
  Zap,
  Users,
  Database,
  Folder,
  ArrowUpDown,
  Radio,
  MessageSquare,
  Calendar,
  RefreshCw,
  Download,
  AlertTriangle,
  ChevronRight,
  ChevronDown,
  Menu,
  type LucideIcon,
} from '@/lib/icons'
import { Button } from '@/components/ui/button'
import { ScrollArea } from '@/components/ui/scroll-area'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
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
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Skeleton } from '@/components/ui/skeleton'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { UsageMetricChart } from './MetricChart'
import {
  type UsageCategory,
  type UsageState,
  generateMockUsageData,
  getUsagePercentage,
  getUsageStatus,
} from './data'

// ============================================================================
// ICON MAPPING
// ============================================================================

const iconMap: Record<string, LucideIcon> = {
  Zap,
  Users,
  Database,
  Folder,
  ArrowUpDown,
  Radio,
  MessageSquare,
}

// ============================================================================
// TYPES
// ============================================================================

interface UsageViewProps {
  plan?: 'free' | 'pro' | 'custom'
  className?: string
}

type DateRange = '7d' | '14d' | '30d' | 'cycle'

// ============================================================================
// CATEGORY NAVIGATION ITEM (COLLAPSIBLE)
// ============================================================================

interface CategoryNavItemProps {
  category: UsageCategory
  isActive: boolean
  isExpanded: boolean
  onToggle: () => void
  onMetricClick: (metricId: string) => void
  collapsed?: boolean
}

function CategoryNavItem({
  category,
  isActive,
  isExpanded,
  onToggle,
  onMetricClick,
  collapsed = false,
}: CategoryNavItemProps) {
  const Icon = iconMap[category.icon] || Zap

  // Check if any metric in this category has warning/critical status
  const hasWarning = category.metrics.some((metric) => {
    const percentage = getUsagePercentage(metric.currentValue, metric.quota)
    const status = getUsageStatus(percentage)
    return status === 'warning' || status === 'critical'
  })

  const buttonContent = (
    <button
      onClick={onToggle}
      className={cn(
        'flex w-full items-center gap-3 rounded-md px-3 py-2 text-[13px] font-medium transition-colors',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
        isActive
          ? 'bg-accent text-foreground'
          : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
        collapsed && 'justify-center px-2',
      )}
      aria-expanded={isExpanded}
    >
      <div className="relative">
        <Icon className="h-4 w-4 shrink-0" />
        {hasWarning && (
          <span className="absolute -right-1 -top-1 h-2 w-2 rounded-full bg-amber-500" />
        )}
      </div>
      {!collapsed && (
        <>
          <span className="flex-1 text-left">{category.label}</span>
          {isExpanded ? (
            <ChevronDown className="h-4 w-4 text-muted-foreground/50 transition-transform" />
          ) : (
            <ChevronRight className="h-4 w-4 text-muted-foreground/50 transition-transform" />
          )}
        </>
      )}
    </button>
  )

  if (collapsed) {
    return (
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>{buttonContent}</TooltipTrigger>
          <TooltipContent side="right" sideOffset={8}>
            <p>{category.label}</p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    )
  }

  return (
    <Collapsible open={isExpanded} onOpenChange={onToggle}>
      <CollapsibleTrigger asChild>{buttonContent}</CollapsibleTrigger>
      <CollapsibleContent>
        <div className="ml-7 mt-1 space-y-0.5 border-l border-border pl-3">
          {category.metrics.map((metric) => {
            const percentage = getUsagePercentage(
              metric.currentValue,
              metric.quota,
            )
            const status = getUsageStatus(percentage)
            const hasMetricWarning =
              status === 'warning' || status === 'critical'

            return (
              <button
                key={metric.id}
                onClick={() => onMetricClick(metric.id)}
                className={cn(
                  'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-[12px] transition-colors',
                  'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                  'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
                )}
              >
                {hasMetricWarning && (
                  <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" />
                )}
                <span className="truncate">{metric.name}</span>
              </button>
            )
          })}
        </div>
      </CollapsibleContent>
    </Collapsible>
  )
}

// ============================================================================
// CATEGORY NAVIGATION
// ============================================================================

interface CategoryNavigationProps {
  categories: UsageCategory[]
  activeCategory: string
  expandedCategories: Set<string>
  onCategoryToggle: (categoryId: string) => void
  onMetricClick: (categoryId: string, metricId: string) => void
  className?: string
}

function CategoryNavigation({
  categories,
  activeCategory,
  expandedCategories,
  onCategoryToggle,
  onMetricClick,
  className,
}: CategoryNavigationProps) {
  return (
    <nav
      className={cn('space-y-1', className)}
      role="navigation"
      aria-label="Usage categories"
    >
      {categories.map((category) => (
        <CategoryNavItem
          key={category.id}
          category={category}
          isActive={activeCategory === category.id}
          isExpanded={expandedCategories.has(category.id)}
          onToggle={() => onCategoryToggle(category.id)}
          onMetricClick={(metricId) => onMetricClick(category.id, metricId)}
        />
      ))}
    </nav>
  )
}

// ============================================================================
// MOBILE CATEGORY DRAWER
// ============================================================================

interface MobileCategoryDrawerProps {
  categories: UsageCategory[]
  activeCategory: string
  expandedCategories: Set<string>
  onCategoryToggle: (categoryId: string) => void
  onMetricClick: (categoryId: string, metricId: string) => void
}

function MobileCategoryDrawer({
  categories,
  activeCategory,
  expandedCategories,
  onCategoryToggle,
  onMetricClick,
}: MobileCategoryDrawerProps) {
  const [open, setOpen] = useState(false)
  const activeLabel =
    categories.find((c) => c.id === activeCategory)?.label || 'Select category'

  const handleMetricClick = (categoryId: string, metricId: string) => {
    onMetricClick(categoryId, metricId)
    setOpen(false)
  }

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
        <ScrollArea className="h-[calc(100vh-65px)]">
          <div className="p-3">
            <CategoryNavigation
              categories={categories}
              activeCategory={activeCategory}
              expandedCategories={expandedCategories}
              onCategoryToggle={onCategoryToggle}
              onMetricClick={handleMetricClick}
            />
          </div>
        </ScrollArea>
      </SheetContent>
    </Sheet>
  )
}

// ============================================================================
// LOADING STATE
// ============================================================================

function UsageLoadingState() {
  return (
    <div className="flex h-full">
      {/* Sidebar skeleton */}
      <div className="hidden w-[220px] shrink-0 border-r border-border p-4 lg:block">
        <div className="space-y-2">
          {Array.from({ length: 7 }).map((_, i) => (
            <Skeleton key={i} className="h-9 w-full rounded-md" />
          ))}
        </div>
      </div>

      {/* Content skeleton */}
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

// ============================================================================
// ERROR STATE
// ============================================================================

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

// ============================================================================
// EMPTY STATE
// ============================================================================

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

// ============================================================================
// MAIN COMPONENT
// ============================================================================

export function UsageView({ plan = 'pro', className }: UsageViewProps) {
  // State
  const [activeCategory, setActiveCategory] = useState('compute')
  const [expandedCategories, setExpandedCategories] = useState<Set<string>>(
    () => new Set([]),
  )
  const [dateRange, setDateRange] = useState<DateRange>('30d')
  const [state, setState] = useState<UsageState>('success')

  // Generate mock data based on plan
  const usageData = useMemo(() => generateMockUsageData(plan), [plan])

  // Get active category data
  const activeCategoryData = usageData.categories.find(
    (c) => c.id === activeCategory,
  )

  // Check for any warnings across all categories
  const hasAnyWarnings = useMemo(() => {
    return usageData.categories.some((category) =>
      category.metrics.some((metric) => {
        const percentage = getUsagePercentage(metric.currentValue, metric.quota)
        const status = getUsageStatus(percentage)
        return status === 'warning' || status === 'critical'
      }),
    )
  }, [usageData.categories])

  // Handle category toggle (expand/collapse)
  const handleCategoryToggle = (categoryId: string) => {
    setExpandedCategories((prev) => {
      const next = new Set(prev)
      if (next.has(categoryId)) {
        next.delete(categoryId)
      } else {
        next.add(categoryId)
      }
      return next
    })
    setActiveCategory(categoryId)
  }

  // Handle metric click (scroll to chart)
  const handleMetricClick = (categoryId: string, metricId: string) => {
    setActiveCategory(categoryId)
    // Ensure category is expanded
    setExpandedCategories((prev) => {
      const next = new Set(prev)
      next.add(categoryId)
      return next
    })
    // Scroll to the metric chart after a brief delay for state update
    setTimeout(() => {
      const element = document.getElementById(`metric-${metricId}`)
      if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'start' })
      }
    }, 50)
  }

  // Handle refresh
  const handleRefresh = () => {
    setState('loading')
    // Simulate API call
    setTimeout(() => setState('success'), 1000)
  }

  // Handle export
  const handleExport = () => {
    // TODO: Implement CSV/JSON export
  }

  // Render based on state
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
      {/* Desktop sidebar - fixed height, no scroll */}
      <aside className="hidden w-[220px] shrink-0 border-r border-border lg:block">
        <div className="h-full p-4">
          <CategoryNavigation
            categories={usageData.categories}
            activeCategory={activeCategory}
            expandedCategories={expandedCategories}
            onCategoryToggle={handleCategoryToggle}
            onMetricClick={handleMetricClick}
          />
        </div>
      </aside>

      {/* Main content area (header + content) */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {/* Header */}
        <div className="border-b border-border">
          <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h1 className="text-[17px] font-semibold text-foreground">
                  Usage
                </h1>
              </div>

              <div className="flex items-center gap-3">
                {/* Date range selector */}
                <Select
                  value={dateRange}
                  onValueChange={(value) => setDateRange(value as DateRange)}
                >
                  <SelectTrigger className="h-9 w-[160px] text-[13px]">
                    <Calendar className="mr-2 h-4 w-4 text-muted-foreground" />
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="7d">Last 7 days</SelectItem>
                    <SelectItem value="14d">Last 14 days</SelectItem>
                    <SelectItem value="30d">Last 30 days</SelectItem>
                    <SelectItem value="cycle">Billing cycle</SelectItem>
                  </SelectContent>
                </Select>

                {/* Action buttons */}
                <TooltipProvider delayDuration={0}>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={handleRefresh}
                        className="h-9 w-9 p-0"
                      >
                        <RefreshCw className="h-4 w-4" />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent>Refresh</TooltipContent>
                  </Tooltip>
                </TooltipProvider>

                <TooltipProvider delayDuration={0}>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={handleExport}
                        className="h-9 w-9 p-0"
                      >
                        <Download className="h-4 w-4" />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent>Export</TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              </div>
            </div>
          </div>
        </div>

        {/* Warning alert */}
        {hasAnyWarnings && (
          <div className="border-b border-border bg-amber-500/5">
            <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
              <Alert
                variant="default"
                className="border-amber-500/30 bg-transparent"
              >
                <AlertTriangle className="h-4 w-4 text-amber-500" />
                <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                  Approaching usage limits
                </AlertTitle>
                <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                  Some metrics are nearing their plan limits. Consider upgrading
                  your plan to avoid service interruptions.
                </AlertDescription>
              </Alert>
            </div>
          </div>
        )}

        {/* Content area */}
        <div className="flex-1 overflow-y-auto">
          <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
            {/* Mobile category selector */}
            <div className="mb-6 lg:hidden">
              <MobileCategoryDrawer
                categories={usageData.categories}
                activeCategory={activeCategory}
                expandedCategories={expandedCategories}
                onCategoryToggle={handleCategoryToggle}
                onMetricClick={handleMetricClick}
              />
            </div>

            {/* Category header */}
            {activeCategoryData && (
              <>
                <div className="mb-6">
                  <div className="flex items-center gap-3">
                    {(() => {
                      const Icon = iconMap[activeCategoryData.icon] || Zap
                      return (
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                          <Icon className="h-5 w-5 text-primary" />
                        </div>
                      )
                    })()}
                    <div>
                      <h2 className="text-[16px] font-semibold text-foreground">
                        {activeCategoryData.label}
                      </h2>
                      <p className="text-[13px] text-muted-foreground">
                        {activeCategoryData.description}
                      </p>
                    </div>
                  </div>
                </div>

                {/* Metrics grid with IDs for anchor navigation */}
                <div className="space-y-6">
                  {activeCategoryData.metrics.map((metric) => (
                    <div
                      key={metric.id}
                      id={`metric-${metric.id}`}
                      className="scroll-mt-6"
                    >
                      <UsageMetricChart metric={metric} />
                    </div>
                  ))}
                </div>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
