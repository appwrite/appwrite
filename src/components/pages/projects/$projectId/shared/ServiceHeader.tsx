import React, { forwardRef, useRef, useImperativeHandle, useState } from 'react'
import { cn } from '@/lib/utils'
import {
  Search,
  Plus,
  Filter,
  RefreshCw,
  Upload,
  Download,
  ChevronUp,
  ChevronRight,
} from 'lucide-react'
import { Button, buttonVariants } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Link } from '@tanstack/react-router'

export interface Tab {
  id: string
  label: string
  count?: number
  to?: string // Route path for Link-based navigation
  params?: Record<string, string> // Route params if needed
}

export interface Breadcrumb {
  label: string
  href?: string
}

export interface ServiceHeaderRef {
  focusSearch: () => void
}

interface ServiceHeaderProps {
  title: React.ReactNode
  tabs?: Tab[]
  activeTab?: string
  onTabChange?: (tab: string) => void
  searchPlaceholder?: string
  searchValue?: string
  onSearchChange?: (value: string) => void
  createLabel?: string
  onCreate?: () => void
  /** When provided with createParams, renders a Link instead of onClick button (more reliable for navigation) */
  createTo?: string
  createParams?: Record<string, string>
  createDisabled?: boolean
  /** Tooltip when create is disabled (e.g. plan limit or missing permission) */
  createDisabledTooltip?: string
  showFilters?: boolean
  onFilterClick?: () => void
  /** When provided, renders this instead of the default Filters button (e.g. a Popover trigger + content) */
  filterTrigger?: React.ReactNode
  /** When true, the tabs border extends full-width while tabs content stays constrained */
  fullWidthBorder?: boolean
  /** When true, removes max-width constraints to allow full-width layout */
  fullWidth?: boolean
  /** Custom content to render on the right side of the toolbar (before the create button) */
  rightContent?: React.ReactNode
  /** Custom buttons to render in the action buttons group (right before the create button) */
  beforeCreateButtons?: React.ReactNode
  /** Show refresh button */
  showRefresh?: boolean
  onRefresh?: () => void
  /** Whether the refresh is currently in progress */
  isRefreshing?: boolean
  /** Show import button */
  showImport?: boolean
  onImport?: () => void
  /** Tooltip for import button (e.g. "Import CSV") */
  importTooltip?: string
  /** Disable import button (e.g. while import is starting) */
  importDisabled?: boolean
  /** Show export button */
  showExport?: boolean
  onExport?: () => void
  /** Tooltip for export button (e.g. "Export CSV") */
  exportTooltip?: string
  /** Disable export button (e.g. when no rows to export) */
  exportDisabled?: boolean
  /** Allow collapsing the header (hides tabs) */
  collapsible?: boolean
  /** Breadcrumbs to show above the title */
  breadcrumbs?: Breadcrumb[]
  /** Hide the title row entirely */
  hideTitle?: boolean
  /** Renders on the same row as the title (typically end-aligned); use for secondary actions next to the page title */
  titleRightContent?: React.ReactNode
  /** Content to render after the border separator, before the toolbar */
  contentAfterBorder?: React.ReactNode
  /** Show a bottom border under the toolbar row (search/filters/actions) */
  showToolbarBottomBorder?: boolean
}

export const ServiceHeader = forwardRef<ServiceHeaderRef, ServiceHeaderProps>(
  function ServiceHeader(
    {
      title,
      tabs,
      activeTab,
      onTabChange,
      searchPlaceholder = 'Search...',
      searchValue = '',
      onSearchChange,
      createLabel,
      onCreate,
      createTo,
      createParams,
      createDisabled = false,
      createDisabledTooltip,
      showFilters = false,
      onFilterClick,
      filterTrigger,
      fullWidthBorder = false,
      fullWidth = false,
      rightContent,
      beforeCreateButtons,
      showRefresh = false,
      onRefresh,
      isRefreshing = false,
      showImport = false,
      onImport,
      importTooltip,
      importDisabled = false,
      showExport = false,
      onExport,
      exportTooltip,
      exportDisabled = false,
      collapsible = false,
      breadcrumbs,
      hideTitle = false,
      titleRightContent,
      contentAfterBorder,
      showToolbarBottomBorder = false,
    },
    ref,
  ) {
    const searchInputRef = useRef<HTMLInputElement>(null)
    const [isCollapsed, setIsCollapsed] = useState(false)
    const hasToolbar =
      onSearchChange ||
      showFilters ||
      (createLabel && (onCreate || (createTo && createParams))) ||
      rightContent ||
      showRefresh ||
      showImport ||
      showExport ||
      beforeCreateButtons

    // Show tabs if we have tabs and either:
    // 1. activeTab + onTabChange (button-based tabs), OR
    // 2. activeTab + tabs with 'to' property (Link-based tabs)
    const hasLinkTabs =
      tabs && tabs.length > 0 && activeTab && tabs.some((tab) => !!tab.to)
    const hasButtonTabs = tabs && tabs.length > 0 && activeTab && !!onTabChange
    const hasTabs = hasLinkTabs || hasButtonTabs

    // Expose focus method to parent
    useImperativeHandle(ref, () => ({
      focusSearch: () => {
        searchInputRef.current?.focus()
      },
    }))

    const tabsContent = hasTabs ? (
      <div
        className={cn(
          'flex gap-0 overflow-x-auto px-4 sm:px-6',
          fullWidthBorder && !fullWidth && 'mx-auto w-full max-w-7xl',
          fullWidthBorder && fullWidth && 'w-full',
        )}
        role="tablist"
      >
        {tabs.map((tab) => {
          const isActive = activeTab === tab.id
          const tabContent = (
            <>
              {tab.label}
              {tab.count !== undefined && (
                <span
                  className={cn(
                    'rounded-full px-1.5 py-0.5 text-[10px]',
                    isActive
                      ? 'bg-accent text-foreground'
                      : 'bg-muted text-muted-foreground',
                  )}
                >
                  {tab.count}
                </span>
              )}
              {isActive && (
                <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-foreground" />
              )}
            </>
          )

          // Use Link if route is provided, otherwise use button (backward compatible)
          if (tab.to) {
            return (
              <Link
                key={tab.id}
                to={tab.to as unknown}
                params={tab.params}
                replace
                onMouseDown={(e) => e.preventDefault()}
                role="tab"
                aria-selected={isActive}
                className={cn(
                  'relative flex shrink-0 cursor-pointer items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors rounded-sm',
                  'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                  isActive
                    ? 'text-foreground'
                    : 'text-muted-foreground hover:text-foreground/80',
                )}
              >
                {tabContent}
              </Link>
            )
          }

          return (
            <button
              key={tab.id}
              type="button"
              role="tab"
              aria-selected={isActive}
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => onTabChange?.(tab.id)}
              className={cn(
                'relative flex shrink-0 cursor-pointer focus:cursor-pointer focus-visible:cursor-pointer items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors rounded-sm',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                isActive
                  ? 'text-foreground'
                  : 'text-muted-foreground hover:text-foreground/80',
              )}
            >
              {tabContent}
            </button>
          )
        })}
      </div>
    ) : null

    return (
      <div className={cn(fullWidthBorder && 'w-full')}>
        {/* Title Row - hidden when collapsed */}
        {!isCollapsed && !hideTitle && (
          <div
            className={cn(
              'flex flex-col gap-1 px-4 pt-6 sm:px-6',
              hasTabs ? 'pb-4' : 'pb-6',
              fullWidthBorder && !fullWidth && 'mx-auto w-full max-w-7xl',
              fullWidthBorder && fullWidth && 'w-full',
            )}
          >
            {breadcrumbs && breadcrumbs.length > 0 && (
              <nav className="flex items-center gap-1 text-[13px]">
                {breadcrumbs.map((breadcrumb, index) => (
                  <React.Fragment key={breadcrumb.label}>
                    {breadcrumb.href ? (
                      <Link
                        to={breadcrumb.href}
                        className="text-muted-foreground transition-colors hover:text-foreground"
                      >
                        {breadcrumb.label}
                      </Link>
                    ) : (
                      <span className="text-muted-foreground">
                        {breadcrumb.label}
                      </span>
                    )}
                    {index < breadcrumbs.length - 1 && (
                      <ChevronRight className="h-3.5 w-3.5 text-muted-foreground" />
                    )}
                  </React.Fragment>
                ))}
              </nav>
            )}
            <div className="flex min-w-0 flex-row flex-wrap items-center justify-between gap-x-4 gap-y-3">
              <h1 className="min-w-0 text-[17px] font-semibold text-foreground">
                {title}
              </h1>
              {titleRightContent ? (
                <div className="flex min-w-0 flex-wrap items-center justify-end gap-2 sm:gap-3">
                  {titleRightContent}
                </div>
              ) : null}
            </div>
          </div>
        )}

        {/* Tabs Row - border extends full width, content is constrained */}
        {!isCollapsed && (
          <>
            {fullWidthBorder ? (
              <div className="w-full border-b border-border">{tabsContent}</div>
            ) : hasTabs ? (
              <div
                className="flex gap-0 overflow-x-auto border-b border-border px-4 sm:px-6"
                role="tablist"
              >
                {tabs.map((tab) => {
                  const isActive = activeTab === tab.id
                  const tabContent = (
                    <>
                      {tab.label}
                      {isActive && (
                        <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-foreground" />
                      )}
                    </>
                  )

                  // Use Link if route is provided, otherwise use button (backward compatible)
                  if (tab.to) {
                    return (
                      <Link
                        key={tab.id}
                        to={tab.to as unknown}
                        params={tab.params}
                        replace
                        onMouseDown={(e) => e.preventDefault()}
                        role="tab"
                        aria-selected={isActive}
                        className={cn(
                          'relative flex shrink-0 cursor-pointer items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors rounded-sm',
                          'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                          isActive
                            ? 'text-foreground'
                            : 'text-muted-foreground hover:text-foreground/80',
                        )}
                      >
                        {tabContent}
                      </Link>
                    )
                  }

                  return (
                    <button
                      key={tab.id}
                      type="button"
                      role="tab"
                      aria-selected={isActive}
                      onMouseDown={(e) => e.preventDefault()}
                      onClick={() => onTabChange?.(tab.id)}
                      className={cn(
                        'relative flex shrink-0 cursor-pointer items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors rounded-sm',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                        isActive
                          ? 'text-foreground'
                          : 'text-muted-foreground hover:text-foreground/80',
                      )}
                    >
                      {tabContent}
                    </button>
                  )
                })}
              </div>
            ) : (
              <div className="border-b border-border" />
            )}
          </>
        )}

        {/* Content after border separator */}
        {contentAfterBorder}

        {/* Toolbar Row - Search, Filters, CTA */}
        {hasToolbar && (
          <div
            className={cn(
              'flex min-w-0 flex-nowrap items-center gap-3 px-4 py-4 sm:px-6',
              fullWidthBorder && !fullWidth && 'mx-auto w-full max-w-7xl',
              (isCollapsed || showToolbarBottomBorder) &&
                'border-b border-border',
            )}
          >
            {/* Search */}
            {onSearchChange && (
              <div className="relative w-64 shrink-0">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <input
                  ref={searchInputRef}
                  type="text"
                  placeholder={searchPlaceholder}
                  value={searchValue}
                  onChange={(e) => onSearchChange(e.target.value)}
                  className="h-9 w-full rounded-md border border-border bg-accent/50 pl-10 pr-4 text-[13px] text-foreground placeholder:text-muted-foreground outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
              </div>
            )}

            {/* Filters */}
            {showFilters &&
              (filterTrigger ?? (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={onFilterClick}
                  className="h-9 gap-2 border-border bg-transparent text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                  <Filter className="h-3.5 w-3.5" />
                  Filters
                </Button>
              ))}

            {/* Right Content (e.g., view toggle) */}
            {rightContent}

            {/* Action Buttons Group */}
            <div className="ml-auto flex items-center gap-2">
              <TooltipProvider delayDuration={0}>
                {/* Refresh Button */}
                {showRefresh && (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={onRefresh}
                        disabled={isRefreshing}
                        className="h-9 w-9 p-0 border-border bg-transparent text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-50"
                      >
                        <RefreshCw
                          className={cn(
                            'h-4 w-4 transition-transform duration-500',
                            isRefreshing && 'animate-spin',
                          )}
                        />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">
                      <p>Refresh</p>
                    </TooltipContent>
                  </Tooltip>
                )}

                {/* Import Button */}
                {showImport && (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={onImport}
                        disabled={importDisabled}
                        className="h-9 w-9 p-0 border-border bg-transparent text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-50"
                      >
                        <Upload className="h-4 w-4" />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">
                      <p>{importTooltip ?? 'Import'}</p>
                    </TooltipContent>
                  </Tooltip>
                )}

                {/* Export Button */}
                {showExport && (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={onExport}
                        disabled={exportDisabled}
                        className="h-9 w-9 p-0 border-border bg-transparent text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-50"
                      >
                        <Download className="h-4 w-4" />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">
                      <p>{exportTooltip ?? 'Export'}</p>
                    </TooltipContent>
                  </Tooltip>
                )}
              </TooltipProvider>

              {/* Before Create Buttons */}
              {beforeCreateButtons}

              {/* Create Button */}
              {createLabel && (onCreate || (createTo && createParams)) && (
                <>
                  {createDisabled ? (
                    <TooltipProvider delayDuration={0}>
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <div>
                            {createTo && createParams ? (
                              <span
                                className={cn(
                                  buttonVariants({ variant: 'brandCta' }),
                                  'inline-flex h-9 cursor-not-allowed items-center justify-center gap-2 px-4 text-[13px] font-medium opacity-50 pointer-events-none',
                                )}
                              >
                                <Plus className="h-4 w-4" />
                                {createLabel}
                              </span>
                            ) : (
                              <Button
                                variant="brandCta"
                                onClick={onCreate}
                                disabled
                                className="h-9 gap-2 px-4 text-[13px] font-medium"
                              >
                                <Plus className="h-4 w-4" />
                                {createLabel}
                              </Button>
                            )}
                          </div>
                        </TooltipTrigger>
                        <TooltipContent side="bottom">
                          <p>
                            {createDisabledTooltip ??
                              "You've reached the limit for this resource on your plan"}
                          </p>
                        </TooltipContent>
                      </Tooltip>
                    </TooltipProvider>
                  ) : (
                    <>
                      {createTo && createParams ? (
                        <Button
                          variant="brandCta"
                          asChild
                          className="h-9 gap-2 px-4 text-[13px] font-medium"
                        >
                          <Link
                            to={createTo as unknown}
                            params={createParams}
                          >
                            <Plus className="h-4 w-4" />
                            {createLabel}
                          </Link>
                        </Button>
                      ) : (
                        <Button
                          variant="brandCta"
                          onClick={onCreate}
                          className="h-9 gap-2 px-4 text-[13px] font-medium"
                        >
                          <Plus className="h-4 w-4" />
                          {createLabel}
                        </Button>
                      )}
                    </>
                  )}
                </>
              )}

              {/* Collapse Toggle Button */}
              {collapsible && (
                <TooltipProvider delayDuration={0}>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setIsCollapsed(!isCollapsed)}
                        className="h-9 w-9 p-0 border-border bg-transparent text-muted-foreground hover:bg-accent hover:text-foreground"
                      >
                        <ChevronUp
                          className={cn(
                            'h-4 w-4 transition-transform duration-200',
                            isCollapsed && 'rotate-180',
                          )}
                        />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">
                      <p>{isCollapsed ? 'Expand header' : 'Collapse header'}</p>
                    </TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              )}
            </div>
          </div>
        )}
      </div>
    )
  },
)
