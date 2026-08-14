import React, { forwardRef, useRef, useImperativeHandle, useState } from 'react'
import { cn } from '@/lib/utils'
import {
  Search,
  Plus,
  Filter,
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
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import { Link } from '@tanstack/react-router'
import {
  SERVICE_HEADER_CONTAINER,
  SERVICE_HEADER_TITLE_CONTAINER,
  serviceHeaderFiltersButton,
  serviceHeaderFiltersLabel,
  serviceHeaderIconOnlyButton,
  serviceHeaderShowLabel,
} from './service-header-container'
import { useT } from '@/lib/i18n/translate'
import {
  analyticsAttrs,
  type AnalyticsActionId,
} from '@/lib/analytics-actions'

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
  /** Curated Plausible action for the create button (see ANALYTICS_ACTIONS) */
  createAnalyticsAction?: AnalyticsActionId
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
  /** Custom content after search/filters in the left toolbar cluster (e.g. list/grid toggle) */
  rightContent?: React.ReactNode
  /** Custom content before the search input in the left toolbar cluster */
  beforeSearchButtons?: React.ReactNode
  /** Custom buttons to render in the action buttons group (right before the create button) */
  beforeCreateButtons?: React.ReactNode
  /** Renders in the right toolbar cluster immediately before the refresh button */
  beforeRefreshButtons?: React.ReactNode
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

const createButtonClassName = cn(
  serviceHeaderIconOnlyButton,
  'text-[13px] font-medium',
)

function ServiceHeaderCreateButton({
  createLabel,
  createDisabled,
  createDisabledTooltip,
  createTo,
  createParams,
  onCreate,
  createAnalyticsAction,
}: {
  createLabel: string
  createDisabled: boolean
  createDisabledTooltip?: string
  createTo?: string
  createParams?: Record<string, string>
  onCreate?: () => void
  createAnalyticsAction?: AnalyticsActionId
}) {
  const t = useT()
  const label = <span className={serviceHeaderShowLabel}>{createLabel}</span>
  const analytics = createAnalyticsAction
    ? analyticsAttrs(createAnalyticsAction)
    : undefined

  if (createDisabled) {
    return (
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>
            <div>
              {createTo && createParams ? (
                <span
                  className={cn(
                    buttonVariants({ variant: 'brandCta' }),
                    createButtonClassName,
                    'inline-flex cursor-not-allowed items-center justify-center opacity-50 pointer-events-none',
                  )}
                  aria-disabled
                >
                  <Plus className="h-4 w-4 shrink-0" />
                  {label}
                  <span className="sr-only @[640px]:hidden">{createLabel}</span>
                </span>
              ) : (
                <Button
                  variant="brandCta"
                  onClick={onCreate}
                  disabled
                  className={createButtonClassName}
                  aria-label={createLabel}
                >
                  <Plus className="h-4 w-4 shrink-0" />
                  {label}
                  <span className="sr-only @[640px]:hidden">{createLabel}</span>
                </Button>
              )}
            </div>
          </TooltipTrigger>
          <TooltipContent side="bottom">
            <p>
              {createDisabledTooltip
                ? t(createDisabledTooltip)
                : t("You've reached the limit for this resource on your plan")}
            </p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    )
  }

  if (createTo && createParams) {
    return (
      <Button variant="brandCta" asChild className={createButtonClassName}>
        <Link
          to={createTo as unknown}
          params={createParams}
          aria-label={createLabel}
          {...analytics}
        >
          <Plus className="h-4 w-4 shrink-0" />
          {label}
          <span className="sr-only @[640px]:hidden">{createLabel}</span>
        </Link>
      </Button>
    )
  }

  return (
    <Button
      variant="brandCta"
      onClick={onCreate}
      className={createButtonClassName}
      aria-label={createLabel}
      {...analytics}
    >
      <Plus className="h-4 w-4 shrink-0" />
      {label}
      <span className="sr-only @[640px]:hidden">{createLabel}</span>
    </Button>
  )
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
      createAnalyticsAction,
      createDisabled = false,
      createDisabledTooltip,
      showFilters = false,
      onFilterClick,
      filterTrigger,
      fullWidthBorder = false,
      fullWidth = false,
      rightContent,
      beforeCreateButtons,
      beforeRefreshButtons,
      beforeSearchButtons,
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
    const t = useT()
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
      beforeCreateButtons ||
      beforeRefreshButtons ||
      beforeSearchButtons
    const showToolbarRow = hasToolbar || collapsible

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
              {t(tab.label)}
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
                <div className="absolute bottom-0 start-0 end-0 h-[2px] bg-foreground" />
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
      <div className={cn('min-w-0 w-full', fullWidthBorder && 'w-full')}>
        {!isCollapsed && (!hideTitle || hasTabs) && (
          <div className="legacy-theme-header">
            {/* Title Row - hidden when collapsed */}
            {!hideTitle && (
              <div
                className={cn(
                  'flex flex-col gap-1 overflow-visible px-4 pt-6 sm:px-6',
                  hasTabs ? 'pb-4' : contentAfterBorder ? 'pb-4' : 'pb-6',
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
                <div
                  className={cn(
                    SERVICE_HEADER_TITLE_CONTAINER,
                    'flex min-w-0 flex-row items-center justify-between gap-x-4',
                  )}
                >
                  <h1 className="min-w-0 flex-1 overflow-hidden text-[17px] font-semibold leading-tight text-foreground">
                    {typeof title === 'string' ? (
                      <span className="block truncate">{title}</span>
                    ) : (
                      title
                    )}
                  </h1>
                  {titleRightContent ? (
                    <div className="flex shrink-0 items-center justify-end gap-2 overflow-visible @[560px]:gap-3">
                      {titleRightContent}
                    </div>
                  ) : null}
                </div>
              </div>
            )}

            {/* Tabs Row - border extends full width, content is constrained */}
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
                      {t(tab.label)}
                      {isActive && (
                        <div className="absolute bottom-0 start-0 end-0 h-[2px] bg-foreground" />
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
          </div>
        )}

        {/* Content after border separator */}
        {contentAfterBorder ? (
          <div className="min-w-0">{contentAfterBorder}</div>
        ) : null}

        {/* Toolbar Row - Search, Filters, CTA */}
        {showToolbarRow && (
          <div
            className={cn(
              SERVICE_HEADER_CONTAINER,
              'flex min-w-0 flex-nowrap items-center gap-2 px-4 @[640px]:gap-3 sm:px-6',
              hasToolbar ? 'py-4' : 'py-2',
              fullWidthBorder && !fullWidth && 'mx-auto w-full max-w-7xl',
              fullWidthBorder && fullWidth && 'w-full',
              (isCollapsed || showToolbarBottomBorder) &&
                'border-b border-border',
            )}
          >
            <div className="flex min-w-0 items-center gap-2 @[640px]:gap-3">
              {beforeSearchButtons ? (
                <div className="flex shrink-0 items-center gap-1.5 @[640px]:gap-2">
                  {beforeSearchButtons}
                </div>
              ) : null}

              {/* Search */}
              {onSearchChange && (
                <div className="relative min-w-0 w-full max-w-xs flex-1 shrink @[520px]:w-64 @[520px]:max-w-none @[520px]:flex-none @[520px]:shrink-0">
                  <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <input
                    ref={searchInputRef}
                    type="text"
                    placeholder={t(searchPlaceholder)}
                    value={searchValue}
                    onChange={(e) => onSearchChange(e.target.value)}
                    className="h-9 w-full min-w-0 rounded-md border border-border bg-accent/50 ps-10 pe-4 text-[13px] text-foreground placeholder:text-muted-foreground outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
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
                    className={cn(
                      'border-border bg-transparent text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground',
                      serviceHeaderFiltersButton,
                    )}
                  >
                    <Filter className="h-3.5 w-3.5 shrink-0" />
                    <span className={serviceHeaderFiltersLabel}>
                      {t('Filters')}
                    </span>
                  </Button>
                ))}

              {/* Left toolbar content (e.g., view toggle) */}
              {rightContent ? (
                <div className="hidden shrink-0 @[480px]:block">
                  {rightContent}
                </div>
              ) : null}
            </div>

            {/* Action Buttons Group */}
            <div className="ms-auto flex min-w-0 shrink-0 items-center gap-1.5 @[640px]:gap-2">
              {beforeRefreshButtons ? (
                <div className="flex shrink-0 items-center gap-1.5 @[640px]:gap-2">
                  {beforeRefreshButtons}
                </div>
              ) : null}
              {showRefresh ? (
                <RefreshButton onClick={onRefresh} isRefreshing={isRefreshing} />
              ) : null}
              <TooltipProvider delayDuration={0}>
                {/* Import Button */}
                {showImport && (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={onImport}
                        disabled={importDisabled}
                        className="h-9 w-9 shrink-0 p-0 border-border bg-transparent text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-50"
                      >
                        <Upload className="h-4 w-4" />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">
                      <p>{t(importTooltip ?? 'Import')}</p>
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
                        className="h-9 w-9 shrink-0 p-0 border-border bg-transparent text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-50"
                      >
                        <Download className="h-4 w-4" />
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">
                      <p>{t(exportTooltip ?? 'Export')}</p>
                    </TooltipContent>
                  </Tooltip>
                )}
              </TooltipProvider>

              {/* Before Create Buttons */}
              {beforeCreateButtons ? (
                <div className="flex shrink-0 items-center gap-1.5 @[640px]:gap-2">
                  {beforeCreateButtons}
                </div>
              ) : null}

              {/* Create Button */}
              {createLabel && (onCreate || (createTo && createParams)) && (
                <ServiceHeaderCreateButton
                  createLabel={createLabel}
                  createDisabled={createDisabled}
                  createDisabledTooltip={createDisabledTooltip}
                  createTo={createTo}
                  createParams={createParams}
                  onCreate={onCreate}
                  createAnalyticsAction={createAnalyticsAction}
                />
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
                      <p>
                        {isCollapsed ? t('Expand header') : t('Collapse header')}
                      </p>
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
