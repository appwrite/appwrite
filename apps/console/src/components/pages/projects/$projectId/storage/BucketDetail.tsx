import { useState, useMemo, useEffect, useCallback } from 'react'
import { useLocation, Link } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import {
  RESOURCE_CARD_GRID_CLASSNAME,
  RESOURCE_CARD_MEDIA_SHELL_CLASSNAME,
  RESOURCE_CARD_SHELL_CLASSNAME,
} from '../shared/ResourceCard'
import { File, List, LayoutGrid, ArrowLeft, AlertCircle } from 'lucide-react'
import { formatBytes } from '@/lib/utils/mock-data'
import {
  useBucket,
  useBucketFiles,
  useProject,
  useOrganizationScopes,
  Dependencies,
} from '@/lib/react-query/hooks'
import { canShowBucketSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
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
import { useNavigate, useParams } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { UploadFileDialog } from './UploadFileDialog'
import { BucketSettings } from './BucketSettings'
import { BucketSecurity } from './BucketSecurity'
import { useUploadQueue } from '@/lib/upload-queue/use-upload-queue'
import type { Models } from '@appwrite.io/console'
import { StorageFilePreviewThumb } from '@/components/global/shared/StorageFilePreviewThumb'
import { useT } from '@/lib/i18n/translate'

export function BucketDetailView() {
  const t = useT()
  const { projectId, bucketId } = useParams({
    strict: false,
  })
  const navigate = useNavigate()
  const location = useLocation()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSecuritySettings = canShowBucketSecuritySettings(access, features)

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const bucketIndex = pathParts.findIndex(
      (part, idx) => part === 'storage' && pathParts[idx + 1] === bucketId,
    )

    if (bucketIndex >= 0 && pathParts[bucketIndex + 2]) {
      const tabFromPath = pathParts[bucketIndex + 2]
      if (tabFromPath === 'settings') {
        return 'settings'
      }
      if (tabFromPath === 'security') {
        return 'security'
      }
    }

    // Default to files for index route
    return 'files'
  }, [location.pathname, bucketId])
  const queryClient = useQueryClient()
  const [searchValue, setSearchValue] = useState('')
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('list')
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(12)
  const [uploadFileDialogOpen, setUploadFileDialogOpen] = useState(false)
  const [selectedFiles, setSelectedFiles] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  // Refetch files list when an upload completes (list uses refetchOnMount: false)
  const refetchFiles = useCallback(() => {
    if (projectId && bucketId) {
      void queryClient.refetchQueries({
        queryKey: ['files', 'project', projectId, 'bucket', bucketId],
      })
    }
  }, [queryClient, projectId, bucketId])

  // Background upload queue
  const { queueUpload } = useUploadQueue(projectId, bucketId, {
    onUploadComplete: refetchFiles,
  })

  // Convert 1-indexed page to 0-indexed for API
  const pageIndexed = currentPage - 1

  // Fetch bucket data
  const { data: bucket } = useBucket(projectId, bucketId)

  // Fetch files
  const { data: filesData, isLoading: filesLoading } = useBucketFiles(
    projectId,
    bucketId,
    pageIndexed,
    pageSize,
    searchValue,
  )

  const files = filesData?.files || []
  const filesTotal = filesData?.total || 0

  const tabs: Tab[] = useMemo(() => {
    const base: Tab[] = [
      {
        id: 'files',
        label: t('Files'),
        to: '/projects/$projectId/storage/$bucketId/',
        params: {
          projectId: projectId as string,
          bucketId: bucketId as string,
        },
      },
      ...(showSecuritySettings
        ? [
            {
              id: 'security' as const,
              label: t('Security'),
              to: '/projects/$projectId/storage/$bucketId/security',
              params: {
                projectId: projectId as string,
                bucketId: bucketId as string,
              },
            },
            {
              id: 'settings' as const,
              label: t('Settings'),
              to: '/projects/$projectId/storage/$bucketId/settings',
              params: {
                projectId: projectId as string,
                bucketId: bucketId as string,
              },
            },
          ]
        : []),
    ]
    return base
  }, [projectId, bucketId, showSecuritySettings, t])

  // Handle file upload - queues in background
  const handleFileUpload = async (data: {
    fileId?: string
    files: File[]
    permissions?: string[]
  }) => {
    try {
      await Promise.all(
        data.files.map((file) =>
          queueUpload(
            file,
            data.files.length === 1 ? data.fileId : undefined,
            data.permissions,
          ),
        ),
      )
      // No toast - progress bar shows upload status
      setUploadFileDialogOpen(false)
      // Files list will automatically reload when upload completes (handled by GlobalUploadProgress)
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const isFilePending = (file: Models.File) => {
    return file.chunksTotal > 0 && file.chunksUploaded < file.chunksTotal
  }

  // Clear selection when navigating or when search changes
  useEffect(() => {
    setSelectedFiles(new Set())
    setDeleteDialogOpen(false)
  }, [location.pathname, projectId, bucketId, searchValue])

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setCurrentPage(1)
    setSelectedFiles(new Set()) // Clear selection on search change
  }

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (fileIds: string[]) => {
      if (!projectId || !bucketId) {
        throw new Error('Project ID and Bucket ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      // Delete all files in parallel
      await Promise.all(
        fileIds.map((fileId) =>
          projectSdk.storage.deleteFile({ bucketId, fileId }),
        ),
      )
    },
    onSuccess: () => {
      // Invalidate and refetch files
      queryClient.invalidateQueries({
        queryKey: Dependencies.FILES,
      })
      toast.success(
        selectedFiles.size > 1
          ? `${t('Successfully deleted')} ${selectedFiles.size} ${t('files')}`
          : `${t('Successfully deleted')} ${selectedFiles.size} ${t('file')}`,
      )
      setSelectedFiles(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete files'))
    },
  })

  const handleBulkDelete = () => {
    if (selectedFiles.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedFiles.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedFiles))
  }

  const toggleFile = (fileId: string) => {
    const newSelected = new Set(selectedFiles)
    if (newSelected.has(fileId)) {
      newSelected.delete(fileId)
    } else {
      newSelected.add(fileId)
    }
    setSelectedFiles(newSelected)
  }

  const toggleAllFiles = () => {
    const nonPendingFiles = files.filter((f) => !isFilePending(f))
    if (selectedFiles.size === nonPendingFiles.length) {
      setSelectedFiles(new Set())
    } else {
      setSelectedFiles(new Set(nonPendingFiles.map((f) => f.$id)))
    }
  }

  const handlePageChange = (page: number) => {
    setCurrentPage(page)
    setSelectedFiles(new Set()) // Clear selection on page change
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setCurrentPage(1)
    setSelectedFiles(new Set()) // Clear selection on page size change
  }

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/storage/$bucketId',
      params: { projectId: projectId as string, bucketId: '-' },
    })
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
        title={
          <div className="flex min-w-0 items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              onClick={handleBack}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span className="truncate">{bucket?.name || t('Bucket')}</span>
            {bucket?.$id ? (
              <CopyableId id={bucket.$id} size="xs" className="shrink-0" />
            ) : null}
          </div>
        }
        tabs={tabs}
        activeTab={activeTab}
        searchPlaceholder={
          activeTab === 'files' ? t('Search files...') : undefined
        }
        searchValue={activeTab === 'files' ? searchValue : ''}
        onSearchChange={activeTab === 'files' ? handleSearchChange : undefined}
        createLabel={activeTab === 'files' ? t('Create file') : undefined}
        onCreate={
          activeTab === 'files'
            ? () => setUploadFileDialogOpen(true)
            : undefined
        }
        showFilters={false}
        fullWidthBorder
        rightContent={activeTab === 'files' ? <ViewToggle /> : undefined}
        contentAfterBorder={
          bucket && !bucket.enabled ? (
            <div className="border-b border-border bg-amber-500/5">
              <div className="w-full px-4 py-3 sm:px-6">
                <Alert
                  variant="default"
                  className="border-amber-500/30 bg-transparent"
                >
                  <AlertCircle className="h-4 w-4 text-amber-500" />
                  <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                    {t('Bucket is disabled')}
                  </AlertTitle>
                  <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                    <span className="inline">
                      {t(
                        'This bucket is disabled and not accessible to end users through the API. Console actions remain available.',
                      )}{' '}
                      <Link
                        to="/projects/$projectId/storage/$bucketId/settings"
                        params={{ projectId: projectId!, bucketId: bucketId! }}
                        className="font-medium underline hover:no-underline inline"
                      >
                        {t('Enable it in the Settings tab')}
                      </Link>{' '}
                      {t('to make it available to end users.')}
                    </span>
                  </AlertDescription>
                </Alert>
              </div>
            </div>
          ) : undefined
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1">
        {activeTab === 'files' && (
          <div className="px-4 pb-4 sm:px-6">
            <>
              {filesLoading ? (
                <div className="rounded-lg border border-border bg-card py-12 text-center">
                  <p className="text-[13px] text-muted-foreground">
                    {t('Loading files...')}
                  </p>
                </div>
              ) : viewMode === 'list' ? (
                files.length > 0 ? (
                  <>
                    <div className="rounded-lg border border-border bg-card overflow-hidden">
                      <Table>
                        <TableHeader>
                          <TableRow className="hover:bg-transparent border-b border-border">
                            <TableHead className="w-[40px] px-4">
                              <Checkbox
                                checked={
                                  files.filter((f) => !isFilePending(f))
                                    .length > 0 &&
                                  files
                                    .filter((f) => !isFilePending(f))
                                    .every((f) => selectedFiles.has(f.$id))
                                }
                                onCheckedChange={toggleAllFiles}
                              />
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[60px]">
                              {t('Preview')}
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                              {t('File')}
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                              {t('Type')}
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                              {t('Size')}
                            </TableHead>
                            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                              {t('Created')}
                            </TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {files.map((file) => {
                            const pending = isFilePending(file)
                            const bucketParams = {
                              projectId: projectId!,
                              bucketId: bucketId!,
                            }
                            const fileSearch = (prev: Record<string, unknown>) => ({
                              ...prev,
                              file: file.$id,
                            })
                            return (
                              <TableRow
                                key={file.$id}
                                className={cn(
                                  pending
                                    ? ''
                                    : 'cursor-pointer transition-colors border-b border-border',
                                  !pending && 'hover:bg-muted/30',
                                  selectedFiles.has(file.$id) && 'bg-muted',
                                )}
                                onClick={(e) => {
                                  if (pending) return
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
                                    to: '/projects/$projectId/storage/$bucketId',
                                    params: bucketParams,
                                    search: fileSearch,
                                  })
                                }}
                              >
                                <TableCell
                                  onClick={(e) => e.stopPropagation()}
                                  className="px-4 py-3"
                                >
                                  {!pending && (
                                    <Checkbox
                                      checked={selectedFiles.has(file.$id)}
                                      onCheckedChange={() =>
                                        toggleFile(file.$id)
                                      }
                                    />
                                  )}
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  {pending ? (
                                    <StorageFilePreviewThumb
                                      projectId={projectId!}
                                      bucketId={bucketId!}
                                      fileId={file.$id}
                                      mimeType={file.mimeType}
                                      name={file.name}
                                      variant="table"
                                      pending={pending}
                                    />
                                  ) : (
                                    <Link
                                      to="/projects/$projectId/storage/$bucketId"
                                      params={bucketParams}
                                      search={fileSearch}
                                      className="block"
                                    >
                                      <StorageFilePreviewThumb
                                        projectId={projectId!}
                                        bucketId={bucketId!}
                                        fileId={file.$id}
                                        mimeType={file.mimeType}
                                        name={file.name}
                                        variant="table"
                                        pending={pending}
                                      />
                                    </Link>
                                  )}
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  {pending ? (
                                    <div className="flex items-center gap-3 min-w-0">
                                      <div className="flex-1 min-w-0">
                                        <p className="truncate text-[13px] font-medium text-foreground">
                                          {file.name}
                                        </p>
                                        <div className="mt-0.5">
                                          <CopyableId id={file.$id} size="xs" />
                                        </div>
                                      </div>
                                      <Badge
                                        variant="secondary"
                                        className="text-[11px] font-medium border px-2 py-0.5 shrink-0"
                                      >
                                        {t('Pending')}
                                      </Badge>
                                    </div>
                                  ) : (
                                    <Link
                                      to="/projects/$projectId/storage/$bucketId"
                                      params={bucketParams}
                                      search={fileSearch}
                                      className="block group"
                                    >
                                      <div className="flex items-center gap-3 min-w-0">
                                        <div className="flex-1 min-w-0">
                                          <p className="truncate text-[13px] font-medium text-foreground group-hover:text-foreground transition-colors">
                                            {file.name}
                                          </p>
                                          <div className="mt-0.5">
                                            <CopyableId
                                              id={file.$id}
                                              size="xs"
                                            />
                                          </div>
                                        </div>
                                      </div>
                                    </Link>
                                  )}
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  {pending ? (
                                    <span className="text-[12px] text-muted-foreground font-mono">
                                      {file.mimeType || '-'}
                                    </span>
                                  ) : (
                                    <Link
                                      to="/projects/$projectId/storage/$bucketId"
                                      params={bucketParams}
                                      search={fileSearch}
                                      className="block"
                                    >
                                      <span className="text-[12px] text-muted-foreground font-mono">
                                        {file.mimeType || '-'}
                                      </span>
                                    </Link>
                                  )}
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  {pending ? (
                                    <span className="text-[12px] text-muted-foreground font-mono text-end block">
                                      {formatBytes(file.sizeOriginal)}
                                    </span>
                                  ) : (
                                    <Link
                                      to="/projects/$projectId/storage/$bucketId"
                                      params={bucketParams}
                                      search={fileSearch}
                                      className="block text-end"
                                    >
                                      <span className="text-[12px] text-muted-foreground font-mono">
                                        {formatBytes(file.sizeOriginal)}
                                      </span>
                                    </Link>
                                  )}
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  {pending ? (
                                    <DateTooltip
                                      date={new Date(file.$createdAt)}
                                      className="text-[12px] text-muted-foreground font-mono text-end block"
                                    />
                                  ) : (
                                    <Link
                                      to="/projects/$projectId/storage/$bucketId"
                                      params={bucketParams}
                                      search={fileSearch}
                                      className="block text-end"
                                    >
                                      <DateTooltip
                                        date={new Date(file.$createdAt)}
                                        className="text-[12px] text-muted-foreground font-mono"
                                      />
                                    </Link>
                                  )}
                                </TableCell>
                              </TableRow>
                            )
                          })}
                        </TableBody>
                      </Table>
                    </div>
                    <Pagination
                      currentPage={currentPage}
                      totalItems={filesTotal}
                      pageSize={pageSize}
                      pageSizeOptions={[12, 18, 36, 72]}
                      onPageChange={handlePageChange}
                      onPageSizeChange={handlePageSizeChange}
                      itemLabel={t('files')}
                    />
                  </>
                ) : (
                  <EmptyState
                    icon={File}
                    title={t('No files found')}
                    description={t('Upload your first file to this bucket')}
                    isEmpty={!searchValue}
                    hasFilters={!!searchValue}
                    variant="card"
                  />
                )
              ) : (
                <div>
                  {files.length > 0 ? (
                    <div className={RESOURCE_CARD_GRID_CLASSNAME}>
                      {files.map((file) => {
                        const pending = isFilePending(file)
                        return (
                          <div
                            key={file.$id}
                            className={cn(
                              RESOURCE_CARD_MEDIA_SHELL_CLASSNAME,
                              pending && 'opacity-75',
                            )}
                            onClick={() => {
                              if (!pending) {
                                navigate({
                                  to: '/projects/$projectId/storage/$bucketId',
                                  params: {
                                    projectId: projectId!,
                                    bucketId: bucketId!,
                                  },
                                  search: (prev: Record<string, unknown>) => ({
                                    ...prev,
                                    file: file.$id,
                                  }),
                                })
                              }
                            }}
                          >
                            <StorageFilePreviewThumb
                              projectId={projectId!}
                              bucketId={bucketId!}
                              fileId={file.$id}
                              mimeType={file.mimeType}
                              name={file.name}
                              variant="grid"
                              pending={pending}
                            />
                            {/* File info */}
                            <div className="p-3">
                              <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0 flex-1">
                                  <p className="truncate text-[13px] font-medium text-foreground">
                                    {file.name}
                                  </p>
                                  <p className="text-[12px] text-muted-foreground">
                                    {file.mimeType}
                                  </p>
                                </div>
                              </div>
                              <div className="mt-2 flex items-center gap-3 text-[12px] text-muted-foreground">
                                <span>{formatBytes(file.sizeOriginal)}</span>
                                {pending && (
                                  <>
                                    <span>•</span>
                                    <Badge
                                      variant="secondary"
                                      className="text-[10px]"
                                    >
                                      {t('Pending')}
                                    </Badge>
                                  </>
                                )}
                              </div>
                            </div>
                          </div>
                        )
                      })}
                    </div>
                  ) : (
                    <div>
                      <EmptyState
                        icon={File}
                        title={
                          searchValue
                            ? `${t("Sorry, we couldn't find")} '${searchValue}'`
                            : t('No files found')
                        }
                        description={
                          searchValue
                            ? t('Try adjusting your search')
                            : t('Create your first file to start storing files')
                        }
                        isEmpty={!searchValue}
                        hasFilters={!!searchValue}
                        variant="card"
                      />
                      {searchValue && (
                        <div className="mt-4 text-center">
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setSearchValue('')}
                          >
                            {t('Clear search')}
                          </Button>
                        </div>
                      )}
                    </div>
                  )}
                  {files.length > 0 && (
                    <Pagination
                      currentPage={currentPage}
                      totalItems={filesTotal}
                      pageSize={pageSize}
                      pageSizeOptions={[12, 18, 36, 72]}
                      onPageChange={handlePageChange}
                      onPageSizeChange={handlePageSizeChange}
                      itemLabel={t('files')}
                    />
                  )}

                  {/* Bulk Delete Action Bar */}
                  {selectedFiles.size > 0 && (
                    <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
                      <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
                        <Badge variant="secondary" className="h-6 px-2.5">
                          {selectedFiles.size}{' '}
                          {selectedFiles.size > 1
                            ? t('files selected')
                            : t('file selected')}
                        </Badge>
                        <div className="flex items-center gap-2">
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setSelectedFiles(new Set())}
                            className="h-8 text-xs"
                          >
                            {t('Cancel')}
                          </Button>
                          <Button
                            variant="destructive"
                            size="sm"
                            onClick={handleBulkDelete}
                            disabled={bulkDeleteMutation.isPending}
                            className="h-8 gap-2"
                          >
                            {t('Delete')}
                          </Button>
                        </div>
                      </div>
                    </div>
                  )}

                  {/* Bulk Delete Confirmation Dialog */}
                  <Dialog
                    open={deleteDialogOpen}
                    onOpenChange={setDeleteDialogOpen}
                  >
                    <DialogContent className="sm:max-w-md p-0">
                      <DialogHeader className="px-6 pt-6 text-start">
                        <DialogTitle>{t('Delete Files')}</DialogTitle>
                        <DialogDescription className="text-[13px] mt-2">
                          {t('Are you sure you want to delete')}{' '}
                          {selectedFiles.size}{' '}
                          {selectedFiles.size > 1 ? t('files') : t('file')}?{' '}
                          {t('This action cannot be undone.')}
                        </DialogDescription>
                      </DialogHeader>

                      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button
                          variant="outline"
                          onClick={() => setDeleteDialogOpen(false)}
                          disabled={bulkDeleteMutation.isPending}
                        >
                          {t('Cancel')}
                        </Button>
                        <Button
                          variant="destructive"
                          onClick={confirmBulkDelete}
                          disabled={bulkDeleteMutation.isPending}
                        >
                          {t('Delete')}
                        </Button>
                      </div>
                    </DialogContent>
                  </Dialog>
                </div>
              )}
            </>
          </div>
        )}

        {activeTab === 'security' && <BucketSecurity />}

        {activeTab === 'settings' && <BucketSettings />}
      </div>

      <UploadFileDialog
        open={uploadFileDialogOpen}
        onOpenChange={setUploadFileDialogOpen}
        onUpload={handleFileUpload}
        bucket={bucket}
        isLoading={false}
      />
    </div>
  )
}
