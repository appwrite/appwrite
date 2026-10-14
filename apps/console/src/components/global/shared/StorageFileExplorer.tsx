import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ID } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  bucketsQueryOptions,
  Dependencies,
  useProjectBuckets,
  useBucketFiles,
  useBucket,
  useOrganizationPlan,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { toast } from 'sonner'
import { File, FolderPlus, List, LayoutGrid, Plus, Search, Upload } from 'lucide-react'
import { cn } from '@/lib/utils'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { formatBytes } from '@/lib/utils/mock-data'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { getStorageFileIcon } from '@/components/global/shared/StorageFilePreviewThumb'
import { useT } from '@/lib/i18n/translate'
import { canCreateBucket } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { CreateBucket } from '@/components/pages/projects/$projectId/storage/_components/CreateBucket'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  normalizeStorageFileAccept,
  storageAcceptToHtmlAccept,
  storageFileMatchesAccept,
  type StorageFileAcceptInput,
} from '@/lib/storage/file-accept'

const tooltipZ = 'z-[10060]'

export type StorageFileSelection = { bucketId: string; fileId: string }

export interface StorageFileExplorerProps {
  projectId: string | null | undefined
  selection: StorageFileSelection | null
  onSelectionChange: (selection: StorageFileSelection | null) => void
  /**
   * When set, files that do not match these rules are disabled and cannot be
   * selected (HTML-style patterns: `video/*`, `.vtt`, `text/vtt`, etc.).
   */
  accept?: StorageFileAcceptInput
  /** Tooltip on disabled files when `accept` rejects them. */
  acceptMismatchTooltip?: string
  /** Bucket to open first instead of the first bucket in the list. */
  initialBucketId?: string | null
  /** Keep the selection when switching buckets, pages, or searching. */
  keepSelectionOnNavigate?: boolean
  /** Stack tooltips and nested dialogs above a fullscreen `WizardLayout`. */
  aboveWizard?: boolean
  /** `dialog` caps the file list height; `inline` fills the parent height. */
  variant?: 'dialog' | 'inline'
  className?: string
}

/**
 * Buckets in the left pane, searchable paginated file list in the right pane
 * (same interaction pattern as CSV import’s bucket source). Fills its parent,
 * so give the parent a fixed height.
 */
export function StorageFileExplorer({
  projectId,
  selection,
  onSelectionChange,
  accept,
  acceptMismatchTooltip,
  initialBucketId,
  keepSelectionOnNavigate = false,
  aboveWizard = false,
  variant = 'dialog',
  className,
}: StorageFileExplorerProps) {
  const t = useT()
  const acceptRules = useMemo(() => normalizeStorageFileAccept(accept), [accept])
  const uploadAcceptAttr = useMemo(
    () => (acceptRules ? storageAcceptToHtmlAccept(acceptRules) : undefined),
    [acceptRules],
  )
  const fileSelectable = useCallback(
    (file: Models.File) =>
      !acceptRules || storageFileMatchesAccept(file, acceptRules),
    [acceptRules],
  )
  const mismatchTooltip =
    acceptMismatchTooltip ?? t('This file type is not supported.')
  const queryClient = useQueryClient()
  const bucketUploadInputRef = useRef<HTMLInputElement>(null)
  const uploadInputId = useMemo(
    () => `storage-explorer-upload-${Math.random().toString(36).slice(2)}`,
    [],
  )
  const [selectedBucketId, setSelectedBucketId] = useState<string | null>(null)
  const [displayedBucketId, setDisplayedBucketId] = useState<string | null>(null)
  const [storageSearch, setStorageSearch] = useState('')
  const [displayedSearch, setDisplayedSearch] = useState('')
  const [storageViewMode, setStorageViewMode] = useState<'list' | 'grid'>('list')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [bucketUploading, setBucketUploading] = useState(false)
  const [createBucketOpen, setCreateBucketOpen] = useState(false)

  const clearSelectionOnNavigate = () => {
    if (!keepSelectionOnNavigate) onSelectionChange(null)
  }

  const { project } = useProject(projectId ?? undefined)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const noCreatePermission = !canCreateBucket(access, features)
  const bucketsLimit = organizationPlan?.buckets ?? 0

  const { data: totalBucketsCount = 0 } = useQuery({
    ...bucketsQueryOptions(projectId, 0, 1, ''),
    select: (d) => d.total ?? 0,
    enabled: !!projectId,
  })
  const createBucketDisabled =
    noCreatePermission ||
    (bucketsLimit > 0 && totalBucketsCount >= bucketsLimit)

  const createBucketTooltip = noCreatePermission
    ? t("You don't have permission to create buckets.")
    : bucketsLimit > 0 && totalBucketsCount >= bucketsLimit
      ? t('Bucket limit reached for your plan.')
      : undefined

  const { buckets } = useProjectBuckets(projectId, 0, 100)

  const createBucketMutation = useMutation({
    mutationFn: async (data: { bucketId?: string; name: string }) => {
      if (!projectId) throw new Error('Project ID is required')
      const projectSdk = sdk.forProject(projectId)
      const newId = data.bucketId || ID.unique()
      return await projectSdk.storage.createBucket({
        bucketId: newId,
        name: data.name,
      })
    },
    onSuccess: async (bucket) => {
      toast.success(`${bucket.name} ${t('has been created')}`)
      await queryClient.invalidateQueries({ queryKey: Dependencies.BUCKETS })
      setCreateBucketOpen(false)
      setSelectedBucketId(bucket.$id)
      clearSelectionOnNavigate()
      setStorageSearch('')
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
  const {
    data: selectedBucketFilesData,
    isSuccess: selectedBucketFilesSuccess,
  } = useBucketFiles(
    projectId,
    selectedBucketId,
    0,
    pageSize,
    selectedBucketId ? storageSearch || undefined : undefined,
    false,
  )
  const { data: bucket } = useBucket(projectId, displayedBucketId)
  const {
    data: requestedFilesData,
    isFetching: filesFetching,
    isLoading: filesLoading,
  } = useBucketFiles(
    projectId,
    displayedBucketId,
    requestedPage - 1,
    pageSize,
    displayedBucketId ? storageSearch || undefined : undefined,
    false,
  )
  const { data: displayedFilesData, isLoading: displayedFilesLoading } =
    useBucketFiles(
      projectId,
      displayedBucketId,
      displayedPage - 1,
      pageSize,
      displayedBucketId ? displayedSearch || undefined : undefined,
      false,
    )
  const files = (displayedFilesData?.files ?? []) as Models.File[]
  const filesTotal = displayedFilesData?.total ?? requestedFilesData?.total ?? 0

  useEffect(() => {
    if (!filesFetching && !filesLoading && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [filesFetching, filesLoading, requestedPage, displayedPage])

  useEffect(() => {
    if (
      !filesFetching &&
      !filesLoading &&
      storageSearch !== displayedSearch &&
      requestedPage === 1
    ) {
      setDisplayedSearch(storageSearch)
      setDisplayedPage(1)
    }
  }, [
    filesFetching,
    filesLoading,
    storageSearch,
    displayedSearch,
    requestedPage,
  ])

  useEffect(() => {
    if (
      selectedBucketId &&
      selectedBucketFilesSuccess &&
      selectedBucketFilesData != null
    ) {
      setDisplayedBucketId(selectedBucketId)
      setDisplayedSearch(storageSearch)
      setRequestedPage(1)
      setDisplayedPage(1)
    }
  }, [selectedBucketId, selectedBucketFilesSuccess, selectedBucketFilesData, storageSearch])

  useEffect(() => {
    setRequestedPage(1)
    setDisplayedPage(1)
  }, [storageSearch])

  useEffect(() => {
    if (buckets.length === 0 || selectedBucketId) return
    const initial =
      initialBucketId && buckets.some((b) => b.$id === initialBucketId)
        ? initialBucketId
        : buckets[0].$id
    setSelectedBucketId(initial)
  }, [buckets, selectedBucketId, initialBucketId])

  const handleStoragePageChange = (page: number) => {
    setRequestedPage(page)
    clearSelectionOnNavigate()
  }

  const handleStoragePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setRequestedPage(1)
    setDisplayedPage(1)
    clearSelectionOnNavigate()
  }

  const handleBucketUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const chosen = e.target.files?.[0]
    if (!chosen || !displayedBucketId || !projectId) return
    e.target.value = ''
    if (
      acceptRules &&
      !storageFileMatchesAccept(
        { mimeType: chosen.type, name: chosen.name },
        acceptRules,
      )
    ) {
      toast.error(mismatchTooltip)
      return
    }
    setBucketUploading(true)
    try {
      const projectSdk = sdk.forProject(projectId)
      const uploaded = await projectSdk.storage.createFile({
        bucketId: displayedBucketId,
        fileId: ID.unique(),
        file: chosen,
      })
      await queryClient.refetchQueries({
        queryKey: ['files', 'project', projectId, 'bucket', displayedBucketId],
      })
      onSelectionChange({ bucketId: uploaded.bucketId, fileId: uploaded.$id })
      toast.success(t('File uploaded'))
    } catch (err) {
      toast.error(getErrorMessage(err))
    } finally {
      setBucketUploading(false)
    }
  }

  const createBucketButton = (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className="h-8 gap-1.5 text-[12px]"
      disabled={createBucketDisabled}
      onClick={() => setCreateBucketOpen(true)}
      {...analyticsAttrs('create-bucket')}
    >
      <Plus className="h-3.5 w-3.5" />
      {t('Create bucket')}
    </Button>
  )

  const tooltipClassName = cn('max-w-xs text-xs', aboveWizard && tooltipZ)
  const isInline = variant === 'inline'

  return (
    <>
      <div className={cn('flex min-h-0 overflow-hidden', className)}>
        <TooltipProvider delayDuration={300}>
          <div
            className={cn(
              'shrink-0 border-e border-border flex flex-col',
              isInline ? 'w-[180px]' : 'w-[220px]',
            )}
          >
            <div className="px-3 py-1.5 border-b border-border flex items-center justify-between gap-2">
              <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                {t('Buckets')}
              </p>
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="inline-flex shrink-0">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="h-7 w-7 p-0"
                      disabled={createBucketDisabled}
                      onClick={() => setCreateBucketOpen(true)}
                      aria-label={t('Create bucket')}
                      {...analyticsAttrs('create-bucket')}
                    >
                      <Plus className="h-3.5 w-3.5" />
                    </Button>
                  </span>
                </TooltipTrigger>
                {createBucketTooltip ? (
                  <TooltipContent side="right" className={tooltipClassName}>
                    {createBucketTooltip}
                  </TooltipContent>
                ) : null}
              </Tooltip>
            </div>
            <div className="flex-1 overflow-y-auto py-1">
              {buckets.map((b) => (
                <button
                  key={b.$id}
                  type="button"
                  onClick={() => {
                    if (b.$id === selectedBucketId) return
                    setSelectedBucketId(b.$id)
                    clearSelectionOnNavigate()
                    setStorageSearch('')
                  }}
                  className={cn(
                    'w-full px-3 py-2 text-start text-[13px] truncate transition-colors',
                    selectedBucketId === b.$id
                      ? 'bg-accent text-accent-foreground font-medium'
                      : 'text-foreground hover:bg-muted/50',
                  )}
                >
                  {b.name}
                </button>
              ))}
              {buckets.length === 0 && (
                <div className="px-2 py-2 space-y-2">
                  <p className="px-1 text-[12px] text-muted-foreground">
                    {t('No buckets yet.')}
                  </p>
                  {createBucketDisabled ? (
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <span className="block w-full">
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8 w-full gap-1.5 text-[12px]"
                            disabled
                            {...analyticsAttrs('create-bucket')}
                          >
                            <Plus className="h-3.5 w-3.5" />
                            {t('Create bucket')}
                          </Button>
                        </span>
                      </TooltipTrigger>
                      {createBucketTooltip ? (
                        <TooltipContent side="right" className={tooltipClassName}>
                          {createBucketTooltip}
                        </TooltipContent>
                      ) : null}
                    </Tooltip>
                  ) : (
                    createBucketButton
                  )}
                </div>
              )}
            </div>
          </div>

          <div className="flex-1 flex flex-col min-w-0 min-h-0">
            {buckets.length === 0 ? (
              <div className="flex-1 flex items-center justify-center p-6">
                <EmptyState
                  icon={FolderPlus}
                  title={t('No buckets yet.')}
                  description={t(
                    'Create a new storage bucket to organize your files.',
                  )}
                  variant="card"
                  iconSize="md"
                  action={
                    createBucketDisabled ? (
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <span>
                            <Button type="button" disabled>
                              {t('Create bucket')}
                            </Button>
                          </span>
                        </TooltipTrigger>
                        {createBucketTooltip ? (
                          <TooltipContent className={tooltipClassName}>
                            {createBucketTooltip}
                          </TooltipContent>
                        ) : null}
                      </Tooltip>
                    ) : (
                      createBucketButton
                    )
                  }
                />
              </div>
            ) : !selectedBucketId ? (
              <div className="flex-1 flex items-center justify-center text-[13px] text-muted-foreground">
                {t('Select a bucket to browse files')}
              </div>
            ) : null}

            {selectedBucketId && buckets.length > 0 && (
              <>
                <div className="shrink-0 border-b border-border px-4 py-3 space-y-3">
                  {displayedBucketId && (
                    <>
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="text-[13px] font-medium text-foreground">
                          {bucket?.name ?? t('Bucket')}
                        </span>
                        <CopyableId
                          id={displayedBucketId}
                          size="xs"
                          maxWidth={120}
                        />
                      </div>
                      <div className="flex items-center gap-2">
                        <div className="relative flex-1 min-w-0">
                          <Search className="absolute start-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
                          <Input
                            placeholder={t('Search files...')}
                            value={storageSearch}
                            onChange={(e) => {
                              setStorageSearch(e.target.value.trim())
                              clearSelectionOnNavigate()
                            }}
                            className="ps-8 h-8 text-[13px]"
                          />
                        </div>
                        <div className="flex items-center gap-1 rounded-md border border-border bg-muted/30 p-0.5">
                          <Button
                            variant="ghost"
                            size="sm"
                            className={cn(
                              'h-7 w-7 p-0',
                              storageViewMode === 'list'
                                ? 'bg-background'
                                : 'hover:bg-transparent',
                            )}
                            type="button"
                            aria-label={t('List view')}
                            onClick={() => setStorageViewMode('list')}
                          >
                            <List className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            className={cn(
                              'h-7 w-7 p-0',
                              storageViewMode === 'grid'
                                ? 'bg-background'
                                : 'hover:bg-transparent',
                            )}
                            type="button"
                            aria-label={t('Grid view')}
                            onClick={() => setStorageViewMode('grid')}
                          >
                            <LayoutGrid className="h-4 w-4" />
                          </Button>
                        </div>
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-8 gap-1.5"
                          asChild
                          disabled={bucketUploading}
                        >
                          <label
                            htmlFor={uploadInputId}
                            className="cursor-pointer flex items-center gap-1.5"
                          >
                            <Upload className="h-3.5 w-3.5" />
                            {t('Upload')}
                          </label>
                        </Button>
                        <input
                          id={uploadInputId}
                          ref={bucketUploadInputRef}
                          type="file"
                          className="sr-only"
                          accept={uploadAcceptAttr}
                          onChange={handleBucketUpload}
                          disabled={bucketUploading}
                        />
                      </div>
                    </>
                  )}
                </div>

                {displayedBucketId && (
                  <div className="flex-1 min-h-0 flex flex-col overflow-hidden">
                    <div
                      className={cn(
                        'overflow-y-auto p-4 min-h-0 flex-1',
                        !isInline && 'max-h-[360px]',
                      )}
                    >
                      {displayedFilesLoading && files.length === 0 ? (
                        <div className="flex flex-1 items-center justify-center py-12 text-[13px] text-muted-foreground">
                          {t('Loading files…')}
                        </div>
                      ) : files.length === 0 && filesTotal === 0 ? (
                        <EmptyState
                          icon={File}
                          title={
                            storageSearch
                              ? t('No files match your search')
                              : t('No files in this bucket')
                          }
                          description={
                            storageSearch
                              ? t('Try a different search or upload a file.')
                              : t(
                                  'Upload a file to this bucket or choose another bucket.',
                                )
                          }
                          variant="card"
                          iconSize="md"
                        />
                      ) : (
                        <>
                          {storageViewMode === 'list' ? (
                            <ul className="divide-y divide-border rounded-lg border border-border bg-card overflow-hidden">
                              {files.map((f) => {
                                const isSelected =
                                  selection?.fileId === f.$id &&
                                  selection?.bucketId === f.bucketId
                                const selectable = fileSelectable(f)
                                const FileIcon = getStorageFileIcon(f.mimeType)
                                const row = (
                                  <button
                                    type="button"
                                    disabled={!selectable}
                                    aria-pressed={isSelected}
                                    onClick={() => {
                                      if (!selectable) return
                                      onSelectionChange({
                                        bucketId: f.bucketId,
                                        fileId: f.$id,
                                      })
                                    }}
                                    className={cn(
                                      'w-full flex items-center gap-3 px-3 py-2.5 text-start text-[13px] transition-colors',
                                      selectable
                                        ? 'hover:bg-accent cursor-pointer'
                                        : 'cursor-not-allowed opacity-50',
                                      isSelected && selectable && 'bg-accent',
                                    )}
                                  >
                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                      <FileIcon className="h-4 w-4" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                      <p className="truncate font-medium text-foreground">
                                        {f.name}
                                      </p>
                                      <p className="text-[11px] text-muted-foreground">
                                        {f.mimeType ?? '-'} ·{' '}
                                        {formatBytes(f.sizeOriginal ?? 0)}
                                      </p>
                                    </div>
                                  </button>
                                )
                                return (
                                  <li key={f.$id}>
                                    {selectable ? (
                                      row
                                    ) : (
                                      <Tooltip>
                                        <TooltipTrigger asChild>
                                          <span className="block w-full">{row}</span>
                                        </TooltipTrigger>
                                        <TooltipContent className={tooltipClassName}>
                                          {mismatchTooltip}
                                        </TooltipContent>
                                      </Tooltip>
                                    )}
                                  </li>
                                )
                              })}
                            </ul>
                          ) : (
                            <div className="grid gap-2 sm:grid-cols-2">
                              {files.map((f) => {
                                const isSelected =
                                  selection?.fileId === f.$id &&
                                  selection?.bucketId === f.bucketId
                                const selectable = fileSelectable(f)
                                const FileIcon = getStorageFileIcon(f.mimeType)
                                const card = (
                                  <button
                                    type="button"
                                    disabled={!selectable}
                                    aria-pressed={isSelected}
                                    onClick={() => {
                                      if (!selectable) return
                                      onSelectionChange({
                                        bucketId: f.bucketId,
                                        fileId: f.$id,
                                      })
                                    }}
                                    className={cn(
                                      'rounded-lg border border-border bg-card p-3 text-start transition-colors w-full',
                                      selectable
                                        ? 'hover:border-primary/40 cursor-pointer'
                                        : 'cursor-not-allowed opacity-50',
                                      isSelected &&
                                        selectable &&
                                        'border-primary ring-1 ring-primary/20',
                                    )}
                                  >
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                      <FileIcon className="h-5 w-5" />
                                    </div>
                                    <p className="mt-2 truncate text-[13px] font-medium text-foreground">
                                      {f.name}
                                    </p>
                                    <p className="text-[11px] text-muted-foreground">
                                      {formatBytes(f.sizeOriginal ?? 0)}
                                    </p>
                                  </button>
                                )
                                return (
                                  <div key={f.$id} className="min-w-0">
                                    {selectable ? (
                                      card
                                    ) : (
                                      <Tooltip>
                                        <TooltipTrigger asChild>
                                          <span className="block w-full">{card}</span>
                                        </TooltipTrigger>
                                        <TooltipContent className={tooltipClassName}>
                                          {mismatchTooltip}
                                        </TooltipContent>
                                      </Tooltip>
                                    )}
                                  </div>
                                )
                              })}
                            </div>
                          )}
                          <Pagination
                            currentPage={displayedPage}
                            totalItems={filesTotal}
                            pageSize={pageSize}
                            pageSizeOptions={[10, 25, 50, 100]}
                            onPageChange={handleStoragePageChange}
                            onPageSizeChange={handleStoragePageSizeChange}
                            itemLabel="files"
                            className="mt-4"
                          />
                        </>
                      )}
                    </div>
                  </div>
                )}
              </>
            )}
          </div>
        </TooltipProvider>
      </div>
      <CreateBucket
        open={createBucketOpen}
        onOpenChange={setCreateBucketOpen}
        onCreate={(data) => createBucketMutation.mutate(data)}
        isLoading={createBucketMutation.isPending}
        aboveWizard={aboveWizard}
      />
    </>
  )
}
