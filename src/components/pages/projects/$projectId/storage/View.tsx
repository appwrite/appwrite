import { useState, useEffect } from 'react'
import { cn } from '@/lib/utils'
import { FolderOpen, List, LayoutGrid, MoreHorizontal, Lock, Trash2, HardDrive } from 'lucide-react'
import { formatBytes, formatNumber } from '@/lib/utils/mock-data'
import { useProjectBuckets, Dependencies, useProject, useOrganizationPlan, fetchProjectBuckets } from '@/lib/react-query/hooks'
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Link, useNavigate, useParams, useLocation } from '@tanstack/react-router'
import { useMutation, useQueryClient, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { ID } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { CreateBucketDialog } from './CreateBucket'
import type { Models } from '@appwrite.io/console'
import { PlanLimitWarning } from '../shared/PlanLimitWarning'

export function StorageView() {
  const { projectId } = useParams({
    strict: false,
  })
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const [searchValue, setSearchValue] = useState('')
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('grid')
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [createBucketDialogOpen, setCreateBucketDialogOpen] = useState(false)
  const [selectedBuckets, setSelectedBuckets] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  // Convert 1-indexed page to 0-indexed for API
  const pageIndexed = currentPage - 1

  // Fetch buckets from the project SDK
  const {
    buckets: apiBuckets,
    total: bucketsTotal,
    isLoading: bucketsLoading,
  } = useProjectBuckets(projectId, pageIndexed, pageSize, searchValue)

  // Fetch total count of buckets without search (for limit checking)
  // This is separate from the search query so the alert doesn't change when searching
  const {
    data: totalBucketsData,
    isLoading: totalBucketsLoading,
  } = useQuery({
    queryKey: ['buckets', 'project', projectId, 'total'],
    queryFn: () => fetchProjectBuckets(projectId!, 0, 1, ''), // Only need total, so limit to 1
    enabled: !!projectId,
    staleTime: 30 * 1000, // 30 seconds
  })

  // Paginated data - buckets are already paginated by the API
  const paginatedBuckets = apiBuckets

  // Get project to get teamId for organization plan
  const { project, isLoading: projectLoading } = useProject(projectId)
  
  // Get organization plan to check limits
  const { plan: organizationPlan, isLoading: planLoading } = useOrganizationPlan(project?.teamId)
  
  // Total count of all buckets (without search) - for limit checking
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
    setCurrentPage(1)
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
        bucketIds.map((bucketId) => projectSdk.storage.deleteBucket({ bucketId })),
      )
    },
    onSuccess: () => {
      // Invalidate and refetch buckets
      queryClient.invalidateQueries({
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
      setSelectedBuckets(new Set(paginatedBuckets.map((b) => (b as Models.Bucket).$id)))
    }
  }

  const handlePageChange = (page: number) => {
    setCurrentPage(page)
    setSelectedBuckets(new Set()) // Clear selection on page change
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setCurrentPage(1)
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
          viewMode === 'list'
            ? 'bg-background shadow-sm'
            : 'hover:bg-transparent',
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
          viewMode === 'grid'
            ? 'bg-background shadow-sm'
            : 'hover:bg-transparent',
        )}
        onClick={() => setViewMode('grid')}
      >
        <LayoutGrid className="h-4 w-4" />
      </Button>
    </div>
  )

  return (
    <div className="flex h-full flex-col">
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
          <PlanLimitWarning
            currentCount={totalBucketsCount}
            limit={bucketsLimit}
            planName={organizationPlan?.name}
            resourceName="buckets"
            orgId={project?.teamId}
            isLoading={projectLoading || planLoading || totalBucketsLoading}
          />
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {viewMode === 'list' ? (
          bucketsLoading ? (
            <div className="rounded-lg border border-border bg-card py-12 text-center">
              <p className="text-[13px] text-muted-foreground">Loading buckets...</p>
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
                                ? 'bg-sky-100 dark:bg-sky-950'
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
                                params: { projectId: projectId!, bucketId: bucketData.$id },
                              })
                            }}
                          >
                            <TableCell onClick={(e) => e.stopPropagation()} className="px-4 py-3">
                              <Checkbox
                                checked={selectedBuckets.has(bucketData.$id)}
                                onCheckedChange={() => toggleBucket(bucketData.$id)}
                              />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/storage/$bucketId/"
                              params={{ projectId: projectId!, bucketId: bucketData.$id }}
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
                                <Badge variant="error" className="text-[11px] font-medium border px-2 py-0.5">
                                  Disabled
                                </Badge>
                              ) : (
                                <Badge variant="success" className="text-[11px] font-medium border px-2 py-0.5">
                                  Enabled
                                </Badge>
                              )}
                            </div>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/storage/$bucketId/"
                              params={{ projectId: projectId!, bucketId: bucketData.$id }}
                              className="block text-right"
                            >
                              {bucketData.$createdAt ? (
                                <DateTooltip
                                  date={bucketData.$createdAt}
                                  className="text-[12px] text-muted-foreground font-mono"
                                />
                              ) : (
                                <span className="text-[12px] text-muted-foreground/50 italic">N/A</span>
                              )}
                            </Link>
                          </TableCell>
                          <TableCell className="px-4 py-3">
                            <Link
                              to="/projects/$projectId/storage/$bucketId/"
                              params={{ projectId: projectId!, bucketId: bucketData.$id }}
                              className="block text-right"
                            >
                              {bucketData.$updatedAt ? (
                                <DateTooltip
                                  date={bucketData.$updatedAt}
                                  className="text-[12px] text-muted-foreground font-mono"
                                />
                              ) : (
                                <span className="text-[12px] text-muted-foreground/50 italic">N/A</span>
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
                currentPage={currentPage}
                totalItems={bucketsTotal}
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
              title="No buckets found"
              description="Create your first bucket to start storing files"
              isEmpty={!searchValue}
              hasFilters={!!searchValue}
              variant="card"
            />
          )
        ) : (
          <>
            {bucketsLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <p className="text-[13px] text-muted-foreground">Loading buckets...</p>
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
                        icon={HardDrive}
                        iconColor="bg-muted text-muted-foreground"
                        status={isDisabled ? 'error' : undefined}
                        statusLabel={isDisabled ? 'Disabled' : undefined}
                        metadata={[
                          ...(bucketData.compression && bucketData.compression !== 'none'
                            ? [
                                {
                                  label: 'Compression',
                                  value: bucketData.compression === 'gzip' ? 'Gzip' : bucketData.compression === 'zstd' ? 'Zstd' : bucketData.compression,
                                },
                              ]
                            : []),
                          ...(bucketData.maximumFileSize && bucketData.maximumFileSize > 0
                            ? [
                                {
                                  label: 'Max size',
                                  value: formatBytes(bucketData.maximumFileSize),
                                },
                              ]
                            : []),
                          ...(bucketData.allowedFileExtensions && bucketData.allowedFileExtensions.length > 0
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
                        ].filter((item) => item.value !== null && item.value !== undefined)}
                        onMenuClick={(e) => {
                          e.preventDefault()
                          e.stopPropagation()
                        }}
                      />
                    </Link>
                  )
                })}
              </div>
            ) : (
              <EmptyState
                icon={HardDrive}
                title="No buckets found"
                description="Create your first bucket to start storing files"
                isEmpty={!searchValue}
                hasFilters={!!searchValue}
                variant="card"
              />
            )}
            {!bucketsLoading && paginatedBuckets.length > 0 && (
              <Pagination
                currentPage={currentPage}
                totalItems={bucketsTotal}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={setCurrentPage}
                onPageSizeChange={(size) => {
                  setPageSize(size)
                  setCurrentPage(1)
                }}
                itemLabel="buckets"
              />
            )}
          </>
        )}

        {/* Bulk Delete Action Bar */}
        {selectedBuckets.size > 0 && (
          <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3 shadow-lg">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedBuckets.size} bucket{selectedBuckets.size > 1 ? 's' : ''} selected
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
                Are you sure you want to delete {selectedBuckets.size} bucket{selectedBuckets.size > 1 ? 's' : ''}? This action cannot be undone.
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

      <CreateBucketDialog
        open={createBucketDialogOpen}
        onOpenChange={setCreateBucketDialogOpen}
        onCreate={(data) => createBucketMutation.mutate(data)}
        isLoading={createBucketMutation.isPending}
      />
    </div>
  )
}
