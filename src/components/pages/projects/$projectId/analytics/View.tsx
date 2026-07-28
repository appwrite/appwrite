import { useState } from 'react'
import { Link, useNavigate, useParams } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import {
  RESOURCE_CARD_GRID_2_COL_CLASSNAME,
  RESOURCE_CARD_INTERACTIVE_CLASSNAME,
  RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
  RESOURCE_CARD_PADDED_CLASSNAME,
  RESOURCE_CARD_SHELL_CLASSNAME} from '../shared/ResourceCard'
import {
  Globe,
  TrendingUp,
  TrendingDown,
  Users,
  Eye,
  Clock,
  MousePointerClick,
  LayoutGrid,
  List,
  ExternalLink,
  Settings,
  Trash2,
  BarChart3,
  Smartphone,
  Monitor,
  Tablet,
  Bot} from 'lucide-react'
import { ServiceHeader } from '../shared/ServiceHeader'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'

// Mock data for tracked websites/apps
const trackedWebsites = [
  {
    id: '507f1f77bcf86cd799439100',
    name: 'Main Marketing Site',
    domain: 'www.acme.com',
    siteId: '507f1f77bcf86cd799439101',
    isAppwriteSite: true,
    appwriteSiteId: '507f1f77bcf86cd799439090',
    status: 'active' as const,
    stats: {
      visitors: 12847,
      visitorsChange: 12.5,
      pageViews: 45230,
      pageViewsChange: 8.3,
      avgDuration: '2m 34s',
      durationChange: -3.2,
      bounceRate: 42.1,
      bounceRateChange: -5.8},
    traffic: { human: 82, ai: 18 },
    topPages: ['/pricing', '/features', '/docs'],
    devices: { desktop: 62, mobile: 31, tablet: 7 },
    createdAt: '2024-01-15T10:30:00Z',
    lastActivity: new Date(Date.now() - 5 * 60 * 1000).toISOString()},
  {
    id: '507f1f77bcf86cd799439102',
    name: 'Documentation Portal',
    domain: 'docs.acme.com',
    siteId: '507f1f77bcf86cd799439103',
    isAppwriteSite: true,
    appwriteSiteId: '507f1f77bcf86cd799439091',
    status: 'active' as const,
    stats: {
      visitors: 8234,
      visitorsChange: 24.1,
      pageViews: 32100,
      pageViewsChange: 18.7,
      avgDuration: '4m 12s',
      durationChange: 15.3,
      bounceRate: 28.5,
      bounceRateChange: -12.4},
    traffic: { human: 61, ai: 39 },
    topPages: ['/getting-started', '/api-reference', '/tutorials'],
    devices: { desktop: 78, mobile: 18, tablet: 4 },
    createdAt: '2024-02-20T14:15:00Z',
    lastActivity: new Date(Date.now() - 12 * 60 * 1000).toISOString()},
  {
    id: '507f1f77bcf86cd799439104',
    name: 'Customer Dashboard',
    domain: 'app.acme.com',
    siteId: '507f1f77bcf86cd799439105',
    isAppwriteSite: false,
    appwriteSiteId: null,
    status: 'active' as const,
    stats: {
      visitors: 3421,
      visitorsChange: 5.2,
      pageViews: 18750,
      pageViewsChange: 3.1,
      avgDuration: '8m 45s',
      durationChange: 2.8,
      bounceRate: 15.2,
      bounceRateChange: -1.5},
    traffic: { human: 96, ai: 4 },
    topPages: ['/dashboard', '/settings', '/billing'],
    devices: { desktop: 85, mobile: 12, tablet: 3 },
    createdAt: '2024-03-05T09:00:00Z',
    lastActivity: new Date(Date.now() - 2 * 60 * 1000).toISOString()},
  {
    id: '507f1f77bcf86cd799439106',
    name: 'Blog',
    domain: 'blog.acme.com',
    siteId: '507f1f77bcf86cd799439107',
    isAppwriteSite: true,
    appwriteSiteId: '507f1f77bcf86cd799439093',
    status: 'inactive' as const,
    stats: {
      visitors: 0,
      visitorsChange: 0,
      pageViews: 0,
      pageViewsChange: 0,
      avgDuration: '0m 0s',
      durationChange: 0,
      bounceRate: 0,
      bounceRateChange: 0},
    traffic: { human: 0, ai: 0 },
    topPages: [],
    devices: { desktop: 0, mobile: 0, tablet: 0 },
    createdAt: '2024-03-10T16:30:00Z',
    lastActivity: null},
]

function formatNumber(num: number): string {
  if (num >= 1000000) {
    return (num / 1000000).toFixed(1) + 'M'
  }
  if (num >= 1000) {
    return (num / 1000).toFixed(1) + 'K'
  }
  return num.toString()
}

function StatHighlight({
  label,
  value,
  change,
  icon: Icon}: {
  label: string
  value: string | number
  change?: number
  icon: typeof Users
}) {
  const isPositive = change && change > 0
  const isNegative = change && change < 0
  const TrendIcon = isPositive ? TrendingUp : TrendingDown

  return (
    <div className="flex items-center gap-3 rounded-lg bg-muted/30 px-3 py-2">
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-background">
        <Icon className="h-4 w-4 text-muted-foreground" />
      </div>
      <div className="min-w-0 flex-1">
        <p className="text-[11px] text-muted-foreground">{label}</p>
        <div className="flex items-center gap-2">
          <span className="text-[14px] font-semibold text-foreground">
            {typeof value === 'number' ? formatNumber(value) : value}
          </span>
          {change !== undefined && change !== 0 && (
            <span
              className={cn(
                'flex items-center gap-0.5 text-[11px] font-medium',
                isPositive && 'text-emerald-600 dark:text-emerald-400',
                isNegative && 'text-red-600 dark:text-red-400',
              )}
            >
              <TrendIcon className="h-3 w-3" />
              {Math.abs(change)}%
            </span>
          )}
        </div>
      </div>
    </div>
  )
}

function AppwriteSitesBadge() {
  const t = useT()
  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
            <Globe className="h-3 w-3" />
            Sites
          </span>
        </TooltipTrigger>
        <TooltipContent side="top">
          <p>{t('Linked to Appwrite Sites deployment')}</p> {/* pragma: allowlist secret */}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

function TrafficBreakdown({
  traffic}: {
  traffic: { human: number; ai: number }
}) {
  const t = useT()
  const total = traffic.human + traffic.ai
  if (total === 0) return null

  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <div className="flex items-center gap-2">
            <div className="flex items-center gap-1.5 rounded-md bg-muted/50 px-2 py-0.5">
              <Users className="h-3 w-3 text-muted-foreground" />
              <span className="text-[10px] font-medium tabular-nums text-muted-foreground">
                {traffic.human}%
              </span>
            </div>
            <div className="flex items-center gap-1.5 rounded-md bg-violet-500/10 px-2 py-0.5">
              <Bot className="h-3 w-3 text-violet-600 dark:text-violet-400" />
              <span className="text-[10px] font-medium tabular-nums text-violet-600 dark:text-violet-400">
                {traffic.ai}%
              </span>
            </div>
          </div>
        </TooltipTrigger>
        <TooltipContent side="top">
          <p>{t('Human vs AI traffic (last 30 days)')}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

function DeviceBreakdown({
  devices}: {
  devices: { desktop: number; mobile: number; tablet: number }
}) {
  const t = useT()
  const total = devices.desktop + devices.mobile + devices.tablet
  if (total === 0) return null

  return (
    <div className="flex items-center gap-3">
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>
            <div className="flex items-center gap-1 text-muted-foreground">
              <Monitor className="h-3.5 w-3.5" />
              <span className="text-[11px]">{devices.desktop}%</span>
            </div>
          </TooltipTrigger>
          <TooltipContent side="top">
            <p>
              {t('Desktop')}: {devices.desktop}%
            </p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>
            <div className="flex items-center gap-1 text-muted-foreground">
              <Smartphone className="h-3.5 w-3.5" />
              <span className="text-[11px]">{devices.mobile}%</span>
            </div>
          </TooltipTrigger>
          <TooltipContent side="top">
            <p>
              {t('Mobile')}: {devices.mobile}%
            </p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>
            <div className="flex items-center gap-1 text-muted-foreground">
              <Tablet className="h-3.5 w-3.5" />
              <span className="text-[11px]">{devices.tablet}%</span>
            </div>
          </TooltipTrigger>
          <TooltipContent side="top">
            <p>
              {t('Tablet')}: {devices.tablet}%
            </p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    </div>
  )
}

export function View() {
  const t = useT()
  const [searchValue, setSearchValue] = useState('')
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')
  const navigate = useNavigate()
  const { projectId } = useParams({ strict: false })

  // Pagination state
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)

  const filteredWebsites = trackedWebsites.filter(
    (site) =>
      site.name.toLowerCase().includes(searchValue.toLowerCase()) ||
      site.domain.toLowerCase().includes(searchValue.toLowerCase()),
  )

  const paginatedWebsites = filteredWebsites.slice(
    (page - 1) * pageSize,
    page * pageSize,
  )

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setPage(1)
  }

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

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Analytics')}
        searchPlaceholder={t('Search websites...')}
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        createLabel={t('Add Website')}
        createAnalyticsAction="add-website"
        onCreate={() => {}}
        showFilters
        fullWidthBorder
        rightContent={<ViewToggle />}
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {viewMode === 'grid' ? (
          <div className="flex flex-col gap-2">
            <div className={RESOURCE_CARD_GRID_2_COL_CLASSNAME}>
              {paginatedWebsites.map((site) => (
                <div
                  key={site.id}
                  className={cn(
                    RESOURCE_CARD_PADDED_CLASSNAME,
                    RESOURCE_CARD_INTERACTIVE_CLASSNAME,
                    RESOURCE_CARD_SHELL_CLASSNAME,
                  )}
                  onClick={() =>
                    navigate({
                      to: '/projects/$projectId/analytics/$websiteId',
                      params: {
                        projectId: projectId as string,
                        websiteId: site.id}})
                  }
                >
                  {/* Header */}
                  <div className="mb-4 flex items-start justify-between">
                    <div className="flex items-start gap-3">
                      <div className="min-w-0">
                        <div className="flex items-center gap-2">
                          <h3 className="truncate text-[14px] font-medium text-foreground">
                            {site.name}
                          </h3>
                          {site.isAppwriteSite && <AppwriteSitesBadge />}
                        </div>
                        <p className="mt-0.5 flex min-w-0 items-center gap-1.5 truncate text-[12px] text-muted-foreground">
                          <span className="truncate">{site.domain}</span>
                          <ExternalLink className="h-3 w-3 shrink-0" />
                        </p>
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
                        <DropdownMenuItem>
                          <MenuItemContent icon={ExternalLink}>
                            {t('Visit')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                        <DropdownMenuItem>
                          <MenuItemContent icon={Settings}>
                            {t('Settings')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem>
                          <MenuItemContent icon={Trash2}>
                            {t('Remove')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>

                  {/* Stats Grid */}
                  {site.status === 'active' ? (
                    <>
                      <div className="grid grid-cols-2 gap-2">
                        <StatHighlight
                          label={t('Visitors')}
                          value={site.stats.visitors}
                          change={site.stats.visitorsChange}
                          icon={Users}
                        />
                        <StatHighlight
                          label={t('Page Views')}
                          value={site.stats.pageViews}
                          change={site.stats.pageViewsChange}
                          icon={Eye}
                        />
                        <StatHighlight
                          label={t('Avg. Duration')}
                          value={site.stats.avgDuration}
                          change={site.stats.durationChange}
                          icon={Clock}
                        />
                        <StatHighlight
                          label={t('Bounce Rate')}
                          value={`${site.stats.bounceRate}%`}
                          change={site.stats.bounceRateChange}
                          icon={MousePointerClick}
                        />
                      </div>

                      {/* Footer */}
                      <div
                        className={cn(
                          RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
                          'flex items-center justify-between',
                        )}
                      >
                        <div className="flex items-center gap-3">
                          <TrafficBreakdown traffic={site.traffic} />
                          <DeviceBreakdown devices={site.devices} />
                        </div>
                        {site.lastActivity && (
                          <DateTooltip
                            date={new Date(site.lastActivity)}
                            className="text-[11px] text-muted-foreground"
                          />
                        )}
                      </div>
                    </>
                  ) : (
                    <div className="flex flex-col items-center justify-center py-6 text-center">
                      <div className="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                        <Globe className="h-5 w-5 text-muted-foreground" />
                      </div>
                      <p className="text-[13px] font-medium text-muted-foreground">
                        {t('No data yet')}
                      </p>
                      <p className="mt-1 text-[12px] text-muted-foreground/70">
                        {t('Waiting for first visitor')}
                      </p>
                    </div>
                  )}
                </div>
              ))}

              {paginatedWebsites.length === 0 && (
                <div className="col-span-full py-12">
                  <EmptyState
                    icon={Globe}
                    title={t('No websites yet')}
                    description={t(
                      'Add your first website to start tracking analytics',
                    )}
                    isEmpty={!searchValue}
                    hasFilters={!!searchValue}
                    iconSize="md"
                  />
                </div>
              )}
            </div>
            {paginatedWebsites.length > 0 && (
              <Pagination
                currentPage={page}
                totalItems={filteredWebsites.length}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={setPage}
                onPageSizeChange={(size) => {
                  setPageSize(size)
                  setPage(1)
                }}
                itemLabel={t('websites')}
              />
            )}
          </div>
        ) : paginatedWebsites.length > 0 ? (
          <>
            <div className="rounded-lg border border-border bg-card">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[250px]">
                      {t('Website')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                      {t('Visitors')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                      {t('Page Views')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                      {t('Avg. Duration')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                      {t('Bounce Rate')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[100px]">
                      {t('Traffic')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[120px]">
                      {t('Last Activity')}
                    </TableHead>
                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px]"></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {paginatedWebsites.map((site) => (
                    <TableRow key={site.id} className="cursor-pointer">
                      <TableCell>
                        <Link
                          to="/projects/$projectId/analytics/$websiteId"
                          params={{
                            projectId: projectId as string,
                            websiteId: site.id}}
                          className="block"
                        >
                          <div className="flex items-center gap-3">
                            <div className="min-w-0">
                              <div className="flex items-center gap-2">
                                <p className="truncate text-[13px] font-medium text-foreground">
                                  {site.name}
                                </p>
                                {site.isAppwriteSite && <AppwriteSitesBadge />}
                              </div>
                              <p className="flex items-center gap-1.5 truncate text-[12px] text-muted-foreground">
                                {site.domain}
                              </p>
                            </div>
                          </div>
                        </Link>
                      </TableCell>
                      <TableCell>
                        <Link
                          to="/projects/$projectId/analytics/$websiteId"
                          params={{
                            projectId: projectId as string,
                            websiteId: site.id}}
                          className="block"
                        >
                          <div className="flex items-center gap-1.5">
                            <span className="text-[13px] text-foreground">
                              {formatNumber(site.stats.visitors)}
                            </span>
                            {site.stats.visitorsChange !== 0 && (
                              <span
                                className={cn(
                                  'text-[11px]',
                                  site.stats.visitorsChange > 0
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-red-600 dark:text-red-400',
                                )}
                              >
                                {site.stats.visitorsChange > 0 ? '+' : ''}
                                {site.stats.visitorsChange}%
                              </span>
                            )}
                          </div>
                        </Link>
                      </TableCell>
                      <TableCell>
                        <Link
                          to="/projects/$projectId/analytics/$websiteId"
                          params={{
                            projectId: projectId as string,
                            websiteId: site.id}}
                          className="block"
                        >
                          <span className="text-[13px] text-muted-foreground">
                            {formatNumber(site.stats.pageViews)}
                          </span>
                        </Link>
                      </TableCell>
                      <TableCell>
                        <Link
                          to="/projects/$projectId/analytics/$websiteId"
                          params={{
                            projectId: projectId as string,
                            websiteId: site.id}}
                          className="block"
                        >
                          <span className="text-[13px] text-muted-foreground">
                            {site.stats.avgDuration}
                          </span>
                        </Link>
                      </TableCell>
                      <TableCell>
                        <Link
                          to="/projects/$projectId/analytics/$websiteId"
                          params={{
                            projectId: projectId as string,
                            websiteId: site.id}}
                          className="block"
                        >
                          <span className="text-[13px] text-muted-foreground">
                            {site.stats.bounceRate}%
                          </span>
                        </Link>
                      </TableCell>
                      <TableCell>
                        <Link
                          to="/projects/$projectId/analytics/$websiteId"
                          params={{
                            projectId: projectId as string,
                            websiteId: site.id}}
                          className="block"
                        >
                          <TrafficBreakdown traffic={site.traffic} />
                        </Link>
                      </TableCell>
                      <TableCell>
                        <Link
                          to="/projects/$projectId/analytics/$websiteId"
                          params={{
                            projectId: projectId as string,
                            websiteId: site.id}}
                          className="block"
                        >
                          {site.lastActivity ? (
                            <DateTooltip
                              date={new Date(site.lastActivity)}
                              className="text-[12px] text-muted-foreground"
                            />
                          ) : (
                            <span className="text-[12px] text-muted-foreground/50">
                              {t('Never')}
                            </span>
                          )}
                        </Link>
                      </TableCell>
                      <TableCell onClick={(e) => e.stopPropagation()}>
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger
                              compact
                              onClick={(e) => e.stopPropagation()}
                            />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem>
                              <MenuItemContent icon={BarChart3}>
                                {t('Analytics')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuItem>
                              <MenuItemContent icon={ExternalLink}>
                                {t('Visit')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuItem>
                              <MenuItemContent icon={Settings}>
                                {t('Settings')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem>
                              <MenuItemContent icon={Trash2}>
                                {t('Remove')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            <Pagination
              currentPage={page}
              totalItems={filteredWebsites.length}
              pageSize={pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={setPage}
              onPageSizeChange={(size) => {
                setPageSize(size)
                setPage(1)
              }}
              itemLabel={t('websites')}
            />
          </>
        ) : (
          <EmptyState
            icon={Globe}
            title={t('No websites yet')}
            description={t('Add your first website to start tracking analytics')}
            isEmpty={!searchValue}
            hasFilters={!!searchValue}
            variant="card"
            iconSize="md"
          />
        )}
      </div>
    </div>
  )
}
