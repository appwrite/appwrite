import { useState, useEffect, useMemo } from 'react'
import {
  useParams,
  useNavigate,
  useLocation,
  Link,
} from '@tanstack/react-router'
import { useQueryClient, useQuery, useMutation } from '@tanstack/react-query'
import { useTheme } from 'next-themes'
import { Globe, List, LayoutGrid } from 'lucide-react'
import { ServiceHeader } from '../shared/ServiceHeader'
import { ResourceCard } from '../shared/ResourceCard'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  useProjectSites,
  Dependencies,
  useProject,
  useOrganizationPlan,
  fetchProjectSites,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'
import { PlanLimitWarning } from '../shared/PlanLimitWarning'

const SITES_PER_PAGE = 25
const SCREENSHOTS_BUCKET_ID = 'screenshots'

export function View() {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const { theme, resolvedTheme } = useTheme()
  const [searchValue, setSearchValue] = useState('')
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(SITES_PER_PAGE)
  const [selectedSites, setSelectedSites] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [loadedScreenshots, setLoadedScreenshots] = useState<Set<string>>(
    new Set(),
  )

  // Get theme for screenshot selection
  const isDark = useMemo(() => {
    if (typeof window === 'undefined') return true // Default to dark during SSR
    return (
      resolvedTheme === 'dark' ||
      (resolvedTheme === 'system' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches) ||
      theme === 'dark'
    )
  }, [theme, resolvedTheme])

  // Reset loaded screenshots when theme changes
  useEffect(() => {
    setLoadedScreenshots(new Set())
  }, [isDark])

  // Helper function to get screenshot URL
  const getScreenshotUrl = (site: Models.Site) => {
    const screenshotId = isDark
      ? (site as any).deploymentScreenshotDark
      : (site as any).deploymentScreenshotLight
    if (!screenshotId) return null
    return sdk.forConsole.storage.getFileDownload({
      bucketId: SCREENSHOTS_BUCKET_ID,
      fileId: screenshotId,
    })
  }

  // Fetch data for the requested page (triggers load when user changes page)
  const {
    total: sitesTotal,
    isLoading: sitesLoading,
    isFetching: sitesFetching,
  } = useProjectSites(projectId, requestedPage - 1, pageSize, searchValue)

  // Fetch data for the displayed page (what we show - stays until new page is ready)
  const {
    sites: apiSites,
    total: displayedTotal,
    isLoading: displayedLoading,
  } = useProjectSites(projectId, displayedPage - 1, pageSize, searchValue)

  // Update displayed page only when requested page data is ready (no flash)
  useEffect(() => {
    if (!sitesFetching && requestedPage !== displayedPage && !sitesLoading) {
      setDisplayedPage(requestedPage)
    }
  }, [sitesFetching, sitesLoading, requestedPage, displayedPage])

  // Only show full loading when we have no data to display (initial load)
  const showLoading = displayedLoading && apiSites.length === 0

  // Get total count from the first page query (no search) - already fetched in route loader
  const { data: totalSitesData } = useQuery({
    queryKey: ['sites', 'project', projectId, 0, pageSize, ''],
    queryFn: () => fetchProjectSites(projectId!, 0, pageSize, ''),
    enabled: !!projectId,
    staleTime: 30 * 1000,
    refetchOnMount: false,
  })

  const paginatedSites = apiSites

  // Get project to get teamId for organization plan
  const { project } = useProject(projectId)

  // Get organization plan to check limits
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)

  // Total count of all sites (without search) - for limit checking
  const totalSitesCount = totalSitesData?.total || 0

  // Check if create button should be disabled
  const sitesLimit = organizationPlan?.sites ?? 0
  const isCreateDisabled = sitesLimit > 0 && totalSitesCount >= sitesLimit

  // Clear selection when navigating or when search changes
  useEffect(() => {
    setSelectedSites(new Set())
    setDeleteDialogOpen(false)
  }, [location.pathname, projectId, searchValue])

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedSites(new Set())
  }

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (siteIds: string[]) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      const projectSdk = sdk.forProject(projectId)
      // Delete all sites in parallel
      await Promise.all(
        siteIds.map((siteId) => projectSdk.sites.delete({ siteId })),
      )
    },
    onSuccess: async () => {
      // Refetch sites list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: Dependencies.SITES,
      })
      toast.success(
        `Successfully deleted ${selectedSites.size} site${selectedSites.size > 1 ? 's' : ''}`,
      )
      setSelectedSites(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to delete sites')
    },
  })

  const handleBulkDelete = () => {
    if (selectedSites.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedSites.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedSites))
  }

  const toggleSite = (siteId: string) => {
    const newSelected = new Set(selectedSites)
    if (newSelected.has(siteId)) {
      newSelected.delete(siteId)
    } else {
      newSelected.add(siteId)
    }
    setSelectedSites(newSelected)
  }

  const toggleAllSites = () => {
    if (selectedSites.size === paginatedSites.length) {
      setSelectedSites(new Set())
    } else {
      setSelectedSites(
        new Set(paginatedSites.map((s) => (s as Models.Site).$id)),
      )
    }
  }

  const handlePageChange = (page: number) => {
    setRequestedPage(page)
    setSelectedSites(new Set())
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedSites(new Set())
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
        title="Sites"
        searchPlaceholder="Search sites..."
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        createLabel="Create site"
        onCreate={() => {
          navigate({
            to: '/projects/$projectId/sites/create',
            params: { projectId: projectId! },
          })
        }}
        createDisabled={isCreateDisabled}
        showFilters={false}
        fullWidthBorder
        rightContent={<ViewToggle />}
        contentAfterBorder={
          project && totalSitesData !== undefined ? (
            <PlanLimitWarning
              currentCount={totalSitesCount}
              limit={sitesLimit}
              planName={organizationPlan?.name}
              resourceName="sites"
              orgId={project?.teamId}
            />
          ) : undefined
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {viewMode === 'list' ? (
          showLoading ? (
            <div className="rounded-lg border border-border bg-card py-12 text-center">
              <p className="text-[13px] text-muted-foreground">
                Loading sites...
              </p>
            </div>
          ) : paginatedSites.length > 0 ? (
            <>
              <div className="rounded-lg border border-border bg-card overflow-hidden">
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border">
                      <TableHead className="w-[40px] px-4">
                        <Checkbox
                          checked={
                            paginatedSites.length > 0 &&
                            selectedSites.size === paginatedSites.length
                          }
                          onCheckedChange={toggleAllSites}
                        />
                      </TableHead>
                      <TableHead className="w-[120px] px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        Preview
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        Site
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                        Created
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                        Updated
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {paginatedSites.map((site) => {
                      const siteData = site as Models.Site
                      return (
                        <TableRow
                          key={siteData.$id}
                          className={cn(
                            'cursor-pointer transition-colors border-b border-border/50',
                            selectedSites.has(siteData.$id)
                              ? 'bg-sky-100 dark:bg-sky-950'
                              : 'hover:bg-muted/30',
                          )}
                          onClick={(e) => {
                            const target = e.target as HTMLElement
                            if (
                              target.closest('button') ||
                              target.closest('[role="checkbox"]') ||
                              target.closest('a')
                            ) {
                              return
                            }
                            navigate({
                              to: '/projects/$projectId/sites/$siteId/',
                              params: {
                                projectId: projectId!,
                                siteId: siteData.$id,
                              },
                            })
                          }}
                        >
                          <TableCell
                            onClick={(e) => e.stopPropagation()}
                            className="px-4 py-3"
                          >
                            <Checkbox
                              checked={selectedSites.has(siteData.$id)}
                              onCheckedChange={() => toggleSite(siteData.$id)}
                            />
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            {(() => {
                              const screenshotUrl = getScreenshotUrl(siteData)
                              const screenshotKey = `${siteData.$id}-${isDark ? 'dark' : 'light'}`
                              const isLoaded =
                                loadedScreenshots.has(screenshotKey)
                              if (screenshotUrl) {
                                return (
                                  <img
                                    src={screenshotUrl}
                                    alt={`${siteData.name || 'Site'} preview`}
                                    onLoad={() => {
                                      setLoadedScreenshots((prev) =>
                                        new Set(prev).add(screenshotKey),
                                      )
                                    }}
                                    className={cn(
                                      'h-12 w-20 rounded border border-border object-cover transition-opacity duration-500',
                                      isLoaded ? 'opacity-100' : 'opacity-0',
                                    )}
                                  />
                                )
                              }
                              return (
                                <div className="flex h-12 w-20 items-center justify-center rounded border border-border/50 bg-gradient-to-br from-muted/40 to-muted/20 backdrop-blur-sm">
                                  <p className="text-[10px] font-medium text-muted-foreground/60 text-center leading-tight px-1">
                                    Preview not available
                                  </p>
                                </div>
                              )
                            })()}
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/sites/$siteId/"
                              params={{
                                projectId: projectId!,
                                siteId: siteData.$id,
                              }}
                              className="block group"
                            >
                              <div className="flex items-center gap-3 min-w-0">
                                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                  <FrameworkIcon
                                    framework={
                                      (siteData as any).buildFramework ||
                                      (siteData as any).buildFrameworkId ||
                                      (siteData as any).framework
                                    }
                                    size="sm"
                                  />
                                </div>
                                <div className="flex-1 min-w-0">
                                  <p className="truncate text-[13px] font-medium text-foreground group-hover:text-primary transition-colors">
                                    {siteData.name || 'Unnamed Site'}
                                  </p>
                                  <div className="mt-0.5">
                                    <CopyableId id={siteData.$id} size="xs" />
                                  </div>
                                </div>
                              </div>
                            </Link>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/sites/$siteId/"
                              params={{
                                projectId: projectId!,
                                siteId: siteData.$id,
                              }}
                              className="block text-right"
                            >
                              {siteData.$createdAt ? (
                                <DateTooltip
                                  date={siteData.$createdAt}
                                  className="text-[12px] text-muted-foreground font-mono"
                                />
                              ) : (
                                <span className="text-[12px] text-muted-foreground/50 italic">
                                  N/A
                                </span>
                              )}
                            </Link>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/sites/$siteId/"
                              params={{
                                projectId: projectId!,
                                siteId: siteData.$id,
                              }}
                              className="block text-right"
                            >
                              {siteData.$updatedAt ? (
                                <DateTooltip
                                  date={siteData.$updatedAt}
                                  className="text-[12px] text-muted-foreground font-mono"
                                />
                              ) : (
                                <span className="text-[12px] text-muted-foreground/50 italic">
                                  N/A
                                </span>
                              )}
                            </Link>
                          </TableCell>
                        </TableRow>
                      )
                    })}
                  </TableBody>
                </Table>
              </div>
              <Pagination
                currentPage={displayedPage}
                totalItems={displayedTotal ?? sitesTotal}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel="sites"
              />
            </>
          ) : (
            <EmptyState
              icon={Globe}
              title="No sites yet"
              description="Create your first site to start deploying static sites"
              isEmpty={!searchValue}
              hasFilters={!!searchValue}
              variant="card"
            />
          )
        ) : (
          <>
            {showLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <p className="text-[13px] text-muted-foreground">
                  Loading sites...
                </p>
              </div>
            ) : paginatedSites.length > 0 ? (
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {paginatedSites.map((site) => {
                  const siteData = site as Models.Site
                  const screenshotUrl = getScreenshotUrl(siteData)
                  return (
                    <Link
                      key={siteData.$id}
                      to="/projects/$projectId/sites/$siteId/"
                      params={{ projectId, siteId: siteData.$id }}
                      className="block group"
                    >
                      <div className="rounded-lg border border-border bg-card overflow-hidden transition-all hover:border-border hover:bg-accent/50">
                        {/* Preview Image */}
                        {screenshotUrl ? (
                          <div className="aspect-video w-full overflow-hidden bg-muted">
                            {(() => {
                              const screenshotKey = `${siteData.$id}-${isDark ? 'dark' : 'light'}`
                              const isLoaded =
                                loadedScreenshots.has(screenshotKey)
                              return (
                                <img
                                  src={screenshotUrl}
                                  alt={`${siteData.name || 'Site'} preview`}
                                  onLoad={() => {
                                    setLoadedScreenshots((prev) =>
                                      new Set(prev).add(screenshotKey),
                                    )
                                  }}
                                  className={cn(
                                    'h-full w-full object-cover transition-opacity duration-500',
                                    isLoaded ? 'opacity-100' : 'opacity-0',
                                  )}
                                />
                              )
                            })()}
                          </div>
                        ) : (
                          <div className="aspect-video w-full flex items-center justify-center bg-gradient-to-br from-muted/50 via-muted/30 to-muted/20 border-b border-border/50 relative overflow-hidden">
                            <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_50%,rgba(0,0,0,0.02),transparent_70%)] dark:bg-[radial-gradient(circle_at_50%_50%,rgba(255,255,255,0.02),transparent_70%)]" />
                            <p className="relative text-[12px] font-medium text-muted-foreground/60">
                              Preview not available
                            </p>
                          </div>
                        )}
                        {/* Card Content */}
                        <div className="p-4">
                          <div className="flex items-start justify-between gap-3">
                            <div className="flex items-start gap-3 min-w-0 flex-1">
                              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                <FrameworkIcon
                                  framework={
                                    (siteData as any).buildFramework ||
                                    (siteData as any).buildFrameworkId ||
                                    (siteData as any).framework
                                  }
                                  size="md"
                                />
                              </div>
                              <div className="min-w-0 flex-1">
                                <h3 className="truncate text-[14px] font-medium text-foreground">
                                  {siteData.name || 'Unnamed Site'}
                                </h3>
                                <div className="mt-1.5">
                                  <CopyableId
                                    id={siteData.$id}
                                    size="xs"
                                    maxWidth={120}
                                    tooltipSide="bottom"
                                  />
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </Link>
                  )
                })}
              </div>
            ) : (
              <EmptyState
                icon={Globe}
                title="No sites yet"
                description="Create your first site to start deploying static sites"
                isEmpty={!searchValue}
                hasFilters={!!searchValue}
                variant="card"
              />
            )}
            {!showLoading && paginatedSites.length > 0 && (
              <Pagination
                currentPage={displayedPage}
                totalItems={displayedTotal ?? sitesTotal}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel="sites"
              />
            )}
          </>
        )}

        {/* Bulk Delete Action Bar */}
        {selectedSites.size > 0 && (
          <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedSites.size} site{selectedSites.size > 1 ? 's' : ''}{' '}
                selected
              </Badge>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedSites(new Set())}
                  className="h-8 text-xs"
                >
                  Cancel
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  onClick={handleBulkDelete}
                  disabled={bulkDeleteMutation.isPending}
                  className="h-8 gap-2"
                >
                  Delete
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* Bulk Delete Confirmation Dialog */}
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-left">
              <DialogTitle>Delete Sites</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Are you sure you want to delete {selectedSites.size} site
                {selectedSites.size > 1 ? 's' : ''}? This action cannot be
                undone.
              </DialogDescription>
            </DialogHeader>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={bulkDeleteMutation.isPending}
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={confirmBulkDelete}
                disabled={bulkDeleteMutation.isPending}
              >
                Delete
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </div>
  )
}
