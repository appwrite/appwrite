import { useState, useEffect } from 'react'
import { cn } from '@/lib/utils'
import { FolderOpen, List, LayoutGrid, Lock, Folder } from 'lucide-react'
import { formatBytes } from '@/lib/utils/mock-data'
import {
  useProjectBuckets,
  Dependencies,
  useProject,
  useOrganizationPlan,
  fetchProjectBuckets,
} from '@/lib/react-query/hooks'
import { ServiceHeader } from '../shared/ServiceHeader'
import { ResourceCard } from '../shared/ResourceCard'
import { CopyableId } from '@/components/global/shared/CopyableId'
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
  Link,
  useNavigate,
  useParams,
  useLocation,
  useSearch,
} from '@tanstack/react-router'
import { useMutation, useQueryClient, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { ID } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { CreateBucket } from './_components/CreateBucket'
import type { Models } from '@appwrite.io/console'
import { PlanLimitWarning } from '../shared/PlanLimitWarning'

export function View() {
  const { projectId } = useParams({
    strict: false,
  })
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false }) as { create?: string }
  const queryClient = useQueryClient()
  const [searchValue, setSearchValue] = useState('')
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [createBucketDialogOpen, setCreateBucketDialogOpen] = useState(false)
  const [selectedBuckets, setSelectedBuckets] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  // Open create bucket dialog when ?create=bucket (e.g. from header plus button)
  useEffect(() => {
    if (search?.create === 'bucket' && !createBucketDialogOpen) {
      setCreateBucketDialogOpen(true)
      navigate({
        to: location.pathname,
        search: (prev: Record<string, unknown>) => {
          if (!prev || typeof prev !== 'object') return {}
          const next = { ...prev }
          delete next.create
          return Object.keys(next).length === 0 ? {} : next
        },
        replace: true,
      })
    }
  }, [search?.create, createBucketDialogOpen, navigate, location.pathname])

  // Fetch data for the requested page (triggers load when user changes page)
  const {
    total: bucketsTotal,
    isLoading: bucketsLoading,
    isFetching: bucketsFetching,
  } = useProjectBuckets(projectId, requestedPage - 1, pageSize, searchValue)

  // Fetch data for the displayed page (what we show - stays until new page is ready)
  const {
    buckets: apiBuckets,
    total: displayedTotal,
    isLoading: displayedLoading,
  } = useProjectBuckets(projectId, displayedPage - 1, pageSize, searchValue)

  // Only show full loading when we have no data to display (initial load)
  const showLoading = displayedLoading && apiBuckets.length === 0

  // Update displayed page only when requested page data is ready (no flash)
  useEffect(() => {
    if (
      !bucketsFetching &&
      requestedPage !== displayedPage &&
      !bucketsLoading
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [bucketsFetching, bucketsLoading, requestedPage, displayedPage])

  // Get total count from the first page query (no search) - already fetched in route loader
  // This is used for limit checking and doesn't change when searching
  const { data: totalBucketsData } = useQuery({
    queryKey: ['buckets', 'project', projectId, 0, pageSize, ''],
    queryFn: () => fetchProjectBuckets(projectId!, 0, pageSize, ''),
    enabled: !!projectId,
    staleTime: 30 * 1000, // 30 seconds
    refetchOnMount: false, // Data is fresh from route loader, no need to refetch
  })

  // Paginated data - buckets are already paginated by the API
  const paginatedBuckets = apiBuckets

  // Get project to get teamId for organization plan
  // Data is guaranteed to be available from route loader (fetchQuery blocks navigation)
  const { project } = useProject(projectId)

  // Get organization plan to check limits
  // Data is guaranteed to be available from route loader if project has teamId
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)

  // Total count of all buckets (without search) - for limit checking
  // Use the total from the first page query (already cached from route loader)
  const totalBucketsCount = totalBucketsData?.total || 0

  // Check if create button should be disabled
  const bucketsLimit = organizationPlan?.buckets ?? 0
  const isCreateDisabled = bucketsLimit > 0 && totalBucketsCount >= bucketsLimit

  // Clear selection when navigating or when search changes
  useEffect(() => {
    setSelectedBuckets(new Set())
    setDeleteDialogOpen(false)
  }, [location.pathname, projectId, searchValue])

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedBuckets(new Set()) // Clear selection on search change
  }

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (bucketIds: string[]) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      const projectSdk = sdk.forProject(projectId)
      // Delete all buckets in parallel
      await Promise.all(
        bucketIds.map((bucketId) =>
          projectSdk.storage.deleteBucket({ bucketId }),
        ),
      )
    },
    onSuccess: async () => {
      // Refetch buckets list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: Dependencies.BUCKETS,
      })
      toast.success(
        `Successfully deleted ${selectedBuckets.size} bucket${selectedBuckets.size > 1 ? 's' : ''}`,
      )
      setSelectedBuckets(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to delete buckets')
    },
  })

  const handleBulkDelete = () => {
    if (selectedBuckets.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedBuckets.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedBuckets))
  }

  const toggleBucket = (bucketId: string) => {
    const newSelected = new Set(selectedBuckets)
    if (newSelected.has(bucketId)) {
      newSelected.delete(bucketId)
    } else {
      newSelected.add(bucketId)
    }
    setSelectedBuckets(newSelected)
  }

  const toggleAllBuckets = () => {
    if (selectedBuckets.size === paginatedBuckets.length) {
      setSelectedBuckets(new Set())
    } else {
      setSelectedBuckets(
        new Set(paginatedBuckets.map((b) => (b as Models.Bucket).$id)),
      )
    }
  }

  const handlePageChange = (page: number) => {
    setRequestedPage(page)
    setSelectedBuckets(new Set()) // Clear selection on page change
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedBuckets(new Set()) // Clear selection on page size change
  }

  // Create bucket mutation
  const createBucketMutation = useMutation({
    mutationFn: async (data: { bucketId?: string; name: string }) => {
      if (!projectId) throw new Error('Project ID is required')
      const projectSdk = sdk.forProject(projectId)
      const bucketId = data.bucketId || ID.unique()
      return await projectSdk.storage.createBucket({
        bucketId,
        name: data.name,
      })
    },
    onSuccess: (bucket) => {
      toast.success(`${bucket.name} has been created`)
      queryClient.invalidateQueries({ queryKey: Dependencies.BUCKETS })
      setCreateBucketDialogOpen(false)
      navigate({
        to: '/projects/$projectId/storage/$bucketId/',
        params: { projectId: projectId!, bucketId: bucket.$id },
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

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
        title="Storage"
        searchPlaceholder="Search buckets..."
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        createLabel="Create bucket"
        onCreate={() => setCreateBucketDialogOpen(true)}
        createDisabled={isCreateDisabled}
        showFilters={false}
        fullWidthBorder
        rightContent={<ViewToggle />}
        contentAfterBorder={
          // Data is guaranteed to be available from route loader (fetchQuery blocks navigation)
          // Only render if we have project and buckets data (project might be null if auth fails)
          // organizationPlan might be null/undefined if no plan exists, which is fine - PlanLimitWarning handles it
          project && totalBucketsData !== undefined ? (
            <PlanLimitWarning
              currentCount={totalBucketsCount}
              limit={bucketsLimit}
              planName={organizationPlan?.name}
              resourceName="buckets"
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
                Loading buckets...
              </p>
            </div>
          ) : paginatedBuckets.length > 0 ? (
            <>
              <div className="rounded-lg border border-border bg-card overflow-hidden">
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border">
                      <TableHead className="w-[40px] px-4">
                        <Checkbox
                          checked={
                            paginatedBuckets.length > 0 &&
                            selectedBuckets.size === paginatedBuckets.length
                          }
                          onCheckedChange={toggleAllBuckets}
                        />
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                        Bucket
                      </TableHead>
                      <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                        Status
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
                    {paginatedBuckets.map((bucket) => {
                      const bucketData = bucket as Models.Bucket
                      const isDisabled = !bucketData.enabled
                      return (
                        <TableRow
                          key={bucketData.$id}
                          className={cn(
                            'cursor-pointer transition-colors border-b border-border/50',
                            selectedBuckets.has(bucketData.$id)
                              ? 'bg-muted'
                              : 'hover:bg-muted/30',
                          )}
                          onClick={(e) => {
                            // Don't navigate if clicking on checkbox, link, or their containers
                            const target = e.target as HTMLElement
                            if (
                              target.closest('button') ||
                              target.closest('[role="checkbox"]') ||
                              target.closest('a')
                            ) {
                              return
                            }
                            navigate({
                              to: '/projects/$projectId/storage/$bucketId/',
                              params: {
                                projectId: projectId!,
                                bucketId: bucketData.$id,
                              },
                            })
                          }}
                        >
                          <TableCell
                            onClick={(e) => e.stopPropagation()}
                            className="px-4 py-3"
                          >
                            <Checkbox
                              checked={selectedBuckets.has(bucketData.$id)}
                              onCheckedChange={() =>
                                toggleBucket(bucketData.$id)
                              }
                            />
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/storage/$bucketId/"
                              params={{
                                projectId: projectId!,
                                bucketId: bucketData.$id,
                              }}
                              className="block group"
                            >
                              <div className="flex items-center gap-3 min-w-0">
                                <div className="flex-1 min-w-0">
                                  <p className="truncate text-[13px] font-medium text-foreground group-hover:text-primary transition-colors">
                                    {bucketData.name}
                                  </p>
                                  <div className="mt-0.5">
                                    <CopyableId id={bucketData.$id} size="xs" />
                                  </div>
                                </div>
                              </div>
                            </Link>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <div className="flex items-center justify-center">
                              {isDisabled ? (
                                <Badge
                                  variant="error"
                                  className="text-[11px] font-medium border px-2 py-0.5"
                                >
                                  Disabled
                                </Badge>
                              ) : (
                                <Badge
                                  variant="success"
                                  className="text-[11px] font-medium border px-2 py-0.5"
                                >
                                  Enabled
                                </Badge>
                              )}
                            </div>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/storage/$bucketId/"
                              params={{
                                projectId: projectId!,
                                bucketId: bucketData.$id,
                              }}
                              className="block text-right"
                            >
                              {bucketData.$createdAt ? (
                                <DateTooltip
                                  date={bucketData.$createdAt}
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
                              to="/projects/$projectId/storage/$bucketId/"
                              params={{
                                projectId: projectId!,
                                bucketId: bucketData.$id,
                              }}
                              className="block text-right"
                            >
                              {bucketData.$updatedAt ? (
                                <DateTooltip
                                  date={bucketData.$updatedAt}
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
                totalItems={displayedTotal ?? bucketsTotal}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel="buckets"
              />
            </>
          ) : (
            <EmptyState
              icon={FolderOpen}
              title="No buckets yet"
              description="Create your first bucket to start storing files"
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
                  Loading buckets...
                </p>
              </div>
            ) : paginatedBuckets.length > 0 ? (
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {paginatedBuckets.map((bucket) => {
                  const bucketData = bucket as Models.Bucket
                  const isDisabled = !bucketData.enabled
                  return (
                    <Link
                      key={bucketData.$id}
                      to="/projects/$projectId/storage/$bucketId/"
                      params={{ projectId, bucketId: bucketData.$id }}
                    >
                      <ResourceCard
                        title={bucketData.name}
                        resourceId={bucketData.$id}
                        icon={Folder}
                        iconColor="bg-muted text-muted-foreground"
                        status={isDisabled ? 'error' : undefined}
                        statusLabel={isDisabled ? 'Disabled' : undefined}
                        metadata={[
                          ...(bucketData.compression &&
                          bucketData.compression !== 'none'
                            ? [
                                {
                                  label: 'Compression',
                                  value:
                                    bucketData.compression === 'gzip'
                                      ? 'Gzip'
                                      : bucketData.compression === 'zstd'
                                        ? 'Zstd'
                                        : bucketData.compression,
                                },
                              ]
                            : []),
                          ...(bucketData.maximumFileSize &&
                          bucketData.maximumFileSize > 0
                            ? [
                                {
                                  label: 'Max size',
                                  value: formatBytes(
                                    bucketData.maximumFileSize,
                                  ),
                                },
                              ]
                            : []),
                          ...(bucketData.allowedFileExtensions &&
                          bucketData.allowedFileExtensions.length > 0
                            ? [
                                {
                                  label: 'Extensions',
                                  value: `${bucketData.allowedFileExtensions.length} ${bucketData.allowedFileExtensions.length === 1 ? 'type' : 'types'}`,
                                },
                              ]
                            : []),
                          {
                            label: 'Encrypted',
                            value: bucketData.encryption ? (
                              <Lock className="h-3.5 w-3.5 text-muted-foreground" />
                            ) : null,
                          },
                        ].filter(
                          (item) =>
                            item.value !== null && item.value !== undefined,
                        )}
                      />
                    </Link>
                  )
                })}
              </div>
            ) : (
              <EmptyState
                icon={Folder}
                title="No buckets yet"
                description="Create your first bucket to start storing files"
                isEmpty={!searchValue}
                hasFilters={!!searchValue}
                variant="card"
              />
            )}
            {!showLoading && paginatedBuckets.length > 0 && (
              <Pagination
                currentPage={displayedPage}
                totalItems={displayedTotal ?? bucketsTotal}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel="buckets"
              />
            )}
          </>
        )}

        {/* Bulk Delete Action Bar */}
        {selectedBuckets.size > 0 && (
          <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedBuckets.size} bucket
                {selectedBuckets.size > 1 ? 's' : ''} selected
              </Badge>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedBuckets(new Set())}
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
              <DialogTitle>Delete Buckets</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Are you sure you want to delete {selectedBuckets.size} bucket
                {selectedBuckets.size > 1 ? 's' : ''}? This action cannot be
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

      <CreateBucket
        open={createBucketDialogOpen}
        onOpenChange={setCreateBucketDialogOpen}
        onCreate={(data) => createBucketMutation.mutate(data)}
        isLoading={createBucketMutation.isPending}
      />
    </div>
  )
}
