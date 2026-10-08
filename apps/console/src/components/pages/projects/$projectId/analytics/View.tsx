import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import {
  RESOURCE_CARD_GRID_CLASSNAME,
  RESOURCE_CARD_INTERACTIVE_CLASSNAME,
  RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
  RESOURCE_CARD_PADDED_CLASSNAME,
  RESOURCE_CARD_SHELL_CLASSNAME,
} from '../shared/ResourceCard'
import { ANALYTICS_PRODUCT_ICON } from '@/lib/analytics/product-icon'
import {
  Clock,
  ExternalLink,
  Eye,
  Globe,
  LayoutGrid,
  List,
  MousePointerClick,
  Trash2,
  Users,
} from 'lucide-react'
import { ServiceHeader } from '../shared/ServiceHeader'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useT } from '@/lib/i18n/translate'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import {
  getDefaultAnalyticsRange,
  getPreviousAnalyticsRange,
  DEFAULT_PAGE_SIZE,
  EMPTY_ANALYTICS_METRIC,
  useAnalyticsProperties,
  useAnalyticsPropertiesStats,
  useDeleteAnalyticsProperty,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canCreateAnalyticsProperty } from '@/lib/console-access-checks'
import { DeleteProperty } from './_components/DeleteProperty'
import { AnalyticsEmptyState } from './_components/AnalyticsEmptyState'
import { PropertyContextMenu } from './_components/PropertyContextMenu'
import { ChangeBadge } from './_components/ChangeBadge'
import { analyticsChangePercent } from './_components/chart-series'
import {
  formatDuration,
  formatNumber,
  formatPercent,
} from './_components/format'

function StatHighlight({
  label,
  value,
  icon: Icon,
  change,
  invert,
}: {
  label: string
  value: string
  icon: typeof Users
  /** % change vs the previous period; omitted when there's nothing to compare. */
  change?: number
  invert?: boolean
}) {
  return (
    <div className="flex items-center gap-3 rounded-lg bg-muted/30 px-3 py-2">
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-background">
        <Icon className="h-4 w-4 text-muted-foreground" />
      </div>
      <div className="min-w-0 flex-1">
        <p className="text-[11px] text-muted-foreground">{label}</p>
        <p className="flex min-w-0 items-baseline gap-2">
          <span className="text-[14px] font-semibold text-foreground">
            {value}
          </span>
          <ChangeBadge change={change} invert={invert} />
        </p>
      </div>
    </div>
  )
}

/**
 * Trend vs the previous period, only when that period had traffic: a
 * property created mid-window would otherwise show a meaningless +100%.
 */
function trendFor(
  current: number,
  previous: Models.AnalyticsMetric | undefined,
  pick: (stats: Models.AnalyticsMetric) => number,
): number | undefined {
  if (!previous || !hasTraffic(previous)) return undefined
  return analyticsChangePercent(current, pick(previous))
}

/** A property only has data once it has received at least one event. */
function hasTraffic(stats: Models.AnalyticsMetric): boolean {
  return stats.events > 0 || stats.visitors > 0 || stats.pageviews > 0
}

export function View() {
  const t = useT()
  const [searchValue, setSearchValue] = useState('')
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')
  const [propertyToDelete, setPropertyToDelete] = useState<
    Models.AnalyticsProperty | undefined
  >(undefined)
  const navigate = useNavigate()
  const { projectId } = useParams({ strict: false })

  // Pagination and search are handled by the API via listProperties({queries, search, total}).
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)

  const { properties, total, isLoading, error, refetch, isFetching } =
    useAnalyticsProperties(projectId, page - 1, pageSize, searchValue)

  const propertyIds = useMemo(
    () => properties.map((property) => property.$id),
    [properties],
  )
  const range = getDefaultAnalyticsRange()
  const { statsByPropertyId } = useAnalyticsPropertiesStats(
    projectId,
    propertyIds,
    range,
  )
  // Same-length window right before, for the trend on each stat.
  const { statsByPropertyId: previousStatsByPropertyId } =
    useAnalyticsPropertiesStats(
      projectId,
      propertyIds,
      getPreviousAnalyticsRange(range),
    )

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const noCreatePermission = !canCreateAnalyticsProperty(access, features)
  const createPermissionTooltip = noCreatePermission
    ? t("You don't have permission to create analytics properties.")
    : undefined

  const deleteMutation = useDeleteAnalyticsProperty(projectId)

  const handleDelete = (propertyId: string) => {
    deleteMutation.mutate(propertyId, {
      onSuccess: () => {
        setPropertyToDelete(undefined)
        toast.success(t('Property deleted'))
      },
      onError: (deleteError) => toast.error(getErrorMessage(deleteError)),
    })
  }

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setPage(1)
  }

  const statsFor = (propertyId: string): Models.AnalyticsMetric =>
    statsByPropertyId[propertyId] ?? EMPTY_ANALYTICS_METRIC

  const ViewToggle = () => (
    <div className="flex items-center gap-1 rounded-md border border-border bg-muted/30 p-0.5">
      <Button
        variant="ghost"
        size="sm"
        className={cn(
          'h-7 w-7 p-0',
          viewMode === 'list' ? 'bg-background' : 'hover:bg-transparent',
        )}
        onClick={() => setViewMode('list')}
      >
        <List className="h-4 w-4" />
      </Button>
      <Button
        variant="ghost"
        size="sm"
        className={cn(
          'h-7 w-7 p-0',
          viewMode === 'grid' ? 'bg-background' : 'hover:bg-transparent',
        )}
        onClick={() => setViewMode('grid')}
      >
        <LayoutGrid className="h-4 w-4" />
      </Button>
    </div>
  )

  const emptyState = (variant?: 'card') => (
    <EmptyState
      icon={Globe}
      title={t('No properties yet')}
      description={t(
        'Create your first property to start tracking analytics for a website or app',
      )}
      isEmpty={!searchValue}
      hasFilters={!!searchValue}
      variant={variant}
      iconSize="md"
    />
  )

  if (error) {
    return (
      <div className="flex flex-col">
        <ServiceHeader
          title={t('Analytics')}
          showFilters={false}
          fullWidthBorder
        />
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
          <EmptyState
            icon={ANALYTICS_PRODUCT_ICON}
            title={t('Could not load analytics properties')}
            description={
              error instanceof Error ? error.message : t('Something went wrong')
            }
            variant="card"
            iconSize="md"
            action={
              <Button
                variant="outline"
                size="sm"
                onClick={() => void refetch()}
                disabled={isFetching}
              >
                {t('Try again')}
              </Button>
            }
          />
        </div>
      </div>
    )
  }

  const showEmptyState = !isLoading && properties.length === 0
  const showsFirstRunEmptyState = showEmptyState && !searchValue

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Analytics')}
        searchPlaceholder={t('Search properties...')}
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        createLabel={t('Create property')}
        createAnalyticsAction="create-analytics-property"
        createTo="/projects/$projectId/analytics/add"
        createParams={{ projectId: projectId as string }}
        createDisabled={noCreatePermission}
        createDisabledTooltip={createPermissionTooltip}
        showFilters
        fullWidthBorder
        // First run: the empty state carries the create action, so the
        // search / view toggle / create toolbar is hidden (as in Stores, Auth).
        hideToolbar={showsFirstRunEmptyState}
        rightContent={<ViewToggle />}
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {showsFirstRunEmptyState ? (
          // First run: the product empty state, like Functions or Firewall.
          <AnalyticsEmptyState
            onCreate={() =>
              navigate({
                to: '/projects/$projectId/analytics/add',
                params: { projectId: projectId as string },
              })
            }
            createDisabled={noCreatePermission}
            createDisabledTooltip={createPermissionTooltip}
          />
        ) : viewMode === 'grid' ? (
          <div className="flex flex-col gap-2">
            {/* 1 / 2 / 3 columns, cards stretched to equal height per row. */}
            <div className={RESOURCE_CARD_GRID_CLASSNAME}>
              {properties.map((property) => {
                const stats = statsFor(property.$id)
                const previous = previousStatsByPropertyId[property.$id]
                return (
                  <PropertyContextMenu
                    key={property.$id}
                    projectId={projectId as string}
                    property={property}
                    onDelete={
                      noCreatePermission
                        ? undefined
                        : () => setPropertyToDelete(property)
                    }
                  >
                  <div
                    className={cn(
                      RESOURCE_CARD_PADDED_CLASSNAME,
                      RESOURCE_CARD_INTERACTIVE_CLASSNAME,
                      RESOURCE_CARD_SHELL_CLASSNAME,
                      'flex flex-col',
                      // The footer supplies the bottom inset (see
                      // RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME).
                      hasTraffic(stats) && 'pb-0',
                    )}
                    onClick={() =>
                      navigate({
                        to: '/projects/$projectId/analytics/$propertyId',
                        params: {
                          projectId: projectId as string,
                          propertyId: property.$id,
                        },
                      })
                    }
                  >
                    {/* Header */}
                    <div className="mb-4 flex items-start justify-between">
                      <div className="flex items-start gap-3">
                        <div className="min-w-0">
                          <div className="flex items-center gap-2">
                            <h3 className="truncate text-[14px] font-medium text-foreground">
                              {property.name}
                            </h3>
                            {!property.enabled && (
                              <Badge
                                variant="warning"
                                className="text-[10px] shrink-0"
                              >
                                {t('Disabled')}
                              </Badge>
                            )}
                          </div>
                          {property.domain ? (
                            <p className="mt-0.5 flex min-w-0 items-center gap-1.5 truncate text-[12px] text-muted-foreground">
                              <span className="truncate">
                                {property.domain}
                              </span>
                              <ExternalLink className="h-3 w-3 shrink-0" />
                            </p>
                          ) : (
                            <p className="mt-0.5 truncate text-[12px] text-muted-foreground">
                              {t('No domain')}
                            </p>
                          )}
                        </div>
                      </div>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <RowActionsMenuTrigger
                            compact
                            revealOnGroupHover
                            onClick={(e) => e.stopPropagation()}
                          />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem
                            onClick={(e) => {
                              e.stopPropagation()
                              navigate({
                                to: '/projects/$projectId/analytics/$propertyId',
                                params: {
                                  projectId: projectId as string,
                                  propertyId: property.$id,
                                },
                              })
                            }}
                          >
                            <MenuItemContent icon={ANALYTICS_PRODUCT_ICON}>
                              {t('Analytics')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                          {property.domain && (
                            <DropdownMenuItem
                              onClick={(e) => {
                                e.stopPropagation()
                                window.open(
                                  `https://${property.domain}`,
                                  '_blank',
                                  'noopener,noreferrer',
                                )
                              }}
                            >
                              <MenuItemContent icon={ExternalLink}>
                                {t('Visit')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                          )}
                          {!noCreatePermission && (
                            <>
                              <DropdownMenuSeparator />
                              <DropdownMenuItem
                                onClick={(e) => {
                                  e.stopPropagation()
                                  setPropertyToDelete(property)
                                }}
                              >
                                <MenuItemContent icon={Trash2}>
                                  {t('Delete')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                            </>
                          )}
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>

                    {/* Stats Grid */}
                    {hasTraffic(stats) ? (
                      <>
                        <div className="grid flex-1 grid-cols-2 content-start gap-2">
                          <StatHighlight
                            label={t('Visitors')}
                            value={formatNumber(stats.visitors)}
                            icon={Users}
                            change={trendFor(stats.visitors, previous, (s) => s.visitors)}
                          />
                          <StatHighlight
                            label={t('Pageviews')}
                            value={formatNumber(stats.pageviews)}
                            icon={Eye}
                            change={trendFor(stats.pageviews, previous, (s) => s.pageviews)}
                          />
                          <StatHighlight
                            label={t('Visit duration')}
                            value={formatDuration(stats.visitDuration)}
                            icon={Clock}
                            change={trendFor(
                              stats.visitDuration,
                              previous,
                              (s) => s.visitDuration,
                            )}
                          />
                          <StatHighlight
                            label={t('Bounce rate')}
                            value={formatPercent(stats.bounceRate)}
                            icon={MousePointerClick}
                            change={trendFor(stats.bounceRate, previous, (s) => s.bounceRate)}
                            invert
                          />
                        </div>

                        {/* Footer */}
                        <div
                          className={cn(
                            RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
                            'mt-4 flex items-center justify-between',
                          )}
                        >
                          <span className="text-[11px] text-muted-foreground">
                            {previous && hasTraffic(previous)
                              ? t('Last 30 days vs previous 30 days')
                              : t('Last 30 days')}
                          </span>
                          <DateTooltip
                            date={new Date(property.$createdAt)}
                            className="text-[11px] text-muted-foreground"
                          />
                        </div>
                      </>
                    ) : (
                      <div className="flex flex-1 flex-col items-center justify-center py-6 text-center">
                        <div className="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                          <Globe className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <p className="text-[13px] font-medium text-muted-foreground">
                          {t('No data yet')}
                        </p>
                        <p className="mt-1 text-[12px] text-muted-foreground/70">
                          {property.enabled
                            ? t('Waiting for first visitor')
                            : t('Tracking is disabled')}
                        </p>
                      </div>
                    )}
                  </div>
                  </PropertyContextMenu>
                )
              })}

              {showEmptyState && (
                <div className="col-span-full py-12">{emptyState()}</div>
              )}
            </div>
            {properties.length > 0 && (
              <Pagination
                currentPage={page}
                totalItems={total}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={setPage}
                onPageSizeChange={(size) => {
                  setPageSize(size)
                  setPage(1)
                }}
                itemLabel={t('properties')}
              />
            )}
          </div>
        ) : properties.length > 0 ? (
          <>
            <div className="rounded-lg border border-border bg-card">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[250px]">
                      {t('Property')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                      {t('Visitors')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                      {t('Pageviews')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                      {t('Visit duration')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                      {t('Bounce rate')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[120px]">
                      {t('Created')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[50px]" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {properties.map((property) => {
                    const stats = statsFor(property.$id)
                    const linkParams = {
                      projectId: projectId as string,
                      propertyId: property.$id,
                    }
                    return (
                      <PropertyContextMenu
                        key={property.$id}
                        projectId={projectId as string}
                        property={property}
                        onDelete={
                          noCreatePermission
                            ? undefined
                            : () => setPropertyToDelete(property)
                        }
                      >
                      <TableRow className="cursor-pointer">
                        <TableCell className="px-4 py-3">
                          <Link
                            to="/projects/$projectId/analytics/$propertyId"
                            params={linkParams}
                            className="block"
                          >
                            <div className="flex items-center gap-3">
                              <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                  <p className="truncate text-[13px] font-medium text-foreground">
                                    {property.name}
                                  </p>
                                  {!property.enabled && (
                                    <Badge
                                      variant="warning"
                                      className="text-[10px] shrink-0"
                                    >
                                      {t('Disabled')}
                                    </Badge>
                                  )}
                                </div>
                                <p className="flex items-center gap-1.5 truncate text-[12px] text-muted-foreground">
                                  {property.domain || t('No domain')}
                                </p>
                              </div>
                            </div>
                          </Link>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <Link
                            to="/projects/$projectId/analytics/$propertyId"
                            params={linkParams}
                            className="block"
                          >
                            <span className="text-[13px] text-foreground">
                              {formatNumber(stats.visitors)}
                            </span>
                          </Link>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <Link
                            to="/projects/$projectId/analytics/$propertyId"
                            params={linkParams}
                            className="block"
                          >
                            <span className="text-[13px] text-muted-foreground">
                              {formatNumber(stats.pageviews)}
                            </span>
                          </Link>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <Link
                            to="/projects/$projectId/analytics/$propertyId"
                            params={linkParams}
                            className="block"
                          >
                            <span className="text-[13px] text-muted-foreground">
                              {formatDuration(stats.visitDuration)}
                            </span>
                          </Link>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <Link
                            to="/projects/$projectId/analytics/$propertyId"
                            params={linkParams}
                            className="block"
                          >
                            <span className="text-[13px] text-muted-foreground">
                              {formatPercent(stats.bounceRate)}
                            </span>
                          </Link>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <Link
                            to="/projects/$projectId/analytics/$propertyId"
                            params={linkParams}
                            className="block"
                          >
                            <DateTooltip
                              date={new Date(property.$createdAt)}
                              className="text-[12px] text-muted-foreground"
                            />
                          </Link>
                        </TableCell>
                        <TableCell
                          className="px-4 py-3 text-right"
                          onClick={(e) => e.stopPropagation()}
                        >
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <RowActionsMenuTrigger
                                compact
                                onClick={(e) => e.stopPropagation()}
                              />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuItem
                                onClick={() =>
                                  navigate({
                                    to: '/projects/$projectId/analytics/$propertyId',
                                    params: linkParams,
                                  })
                                }
                              >
                                <MenuItemContent icon={ANALYTICS_PRODUCT_ICON}>
                                  {t('Analytics')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              {property.domain && (
                                <DropdownMenuItem
                                  onClick={() =>
                                    window.open(
                                      `https://${property.domain}`,
                                      '_blank',
                                      'noopener,noreferrer',
                                    )
                                  }
                                >
                                  <MenuItemContent icon={ExternalLink}>
                                    {t('Visit')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                              )}
                              {!noCreatePermission && (
                                <>
                                  <DropdownMenuSeparator />
                                  <DropdownMenuItem
                                    onClick={() =>
                                      setPropertyToDelete(property)
                                    }
                                  >
                                    <MenuItemContent icon={Trash2}>
                                      {t('Delete')}
                                    </MenuItemContent>
                                  </DropdownMenuItem>
                                </>
                              )}
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </TableCell>
                      </TableRow>
                      </PropertyContextMenu>
                    )
                  })}
                </TableBody>
              </Table>
            </div>
            <Pagination
              currentPage={page}
              totalItems={total}
              pageSize={pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={setPage}
              onPageSizeChange={(size) => {
                setPageSize(size)
                setPage(1)
              }}
              itemLabel={t('properties')}
            />
          </>
        ) : showEmptyState ? (
          emptyState('card')
        ) : null}
      </div>

      <DeleteProperty
        property={propertyToDelete}
        open={!!propertyToDelete}
        onOpenChange={(open) => {
          if (!open) setPropertyToDelete(undefined)
        }}
        onConfirm={handleDelete}
        isLoading={deleteMutation.isPending}
      />
    </div>
  )
}
