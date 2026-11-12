import { useCallback, useEffect, useRef, useState } from 'react'
import { ID } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  useProjectBuckets,
  useBucketFiles,
  useBucket,
} from '@/lib/react-query/hooks'
import { useQueryClient } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { toast } from 'sonner'
import { File, List, LayoutGrid, Search, Upload } from 'lucide-react'
import { cn } from '@/lib/utils'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { formatBytes } from '@/lib/utils/mock-data'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { getStorageFileIcon } from '@/components/global/shared/StorageFilePreviewThumb'
import { useT } from '@/lib/i18n/translate'

const MODAL_WIDTH = 1100
const MODAL_HEIGHT = 600

export type StorageFileSelection = { bucketId: string; fileId: string }

export interface StorageFileExplorerDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string | null | undefined
  title?: string
  description?: string
  /** Primary action label (default Add) */
  confirmLabel?: string
  /** Return false to keep the dialog open (e.g. duplicate rejected). */
  onConfirm: (selection: StorageFileSelection) => void | boolean
}

/**
 * Pick a file from project Storage: buckets in the left pane, searchable paginated
 * file list in the right pane (same interaction pattern as CSV import’s bucket source).
 */
export function StorageFileExplorerDialog({
  open,
  onOpenChange,
  projectId,
  title = 'Select file',
  description = 'Choose a bucket, then pick a file to attach.',
  confirmLabel = 'Add',
  onConfirm,
}: StorageFileExplorerDialogProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const bucketUploadInputRef = useRef<HTMLInputElement>(null)
  const [selectedBucketId, setSelectedBucketId] = useState<string | null>(null)
  const [displayedBucketId, setDisplayedBucketId] = useState<string | null>(null)
  const [selectedStorageFile, setSelectedStorageFile] =
    useState<StorageFileSelection | null>(null)
  const [storageSearch, setStorageSearch] = useState('')
  const [displayedSearch, setDisplayedSearch] = useState('')
  const [storageViewMode, setStorageViewMode] = useState<'list' | 'grid'>('list')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [bucketUploading, setBucketUploading] = useState(false)

  const { buckets } = useProjectBuckets(projectId, 0, 100)
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

  const reset = useCallback(() => {
    setSelectedBucketId(null)
    setDisplayedBucketId(null)
    setSelectedStorageFile(null)
    setStorageSearch('')
    setDisplayedSearch('')
    setRequestedPage(1)
    setDisplayedPage(1)
    if (bucketUploadInputRef.current) bucketUploadInputRef.current.value = ''
  }, [])

  useEffect(() => {
    if (!open) {
      reset()
      return
    }
    if (buckets.length > 0 && !selectedBucketId) {
      setSelectedBucketId(buckets[0].$id)
    }
  }, [open, buckets, selectedBucketId, reset])

  const handleOpenChange = (next: boolean) => {
    if (!next) reset()
    onOpenChange(next)
  }

  const handleStoragePageChange = (page: number) => {
    setRequestedPage(page)
    setSelectedStorageFile(null)
  }

  const handleStoragePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedStorageFile(null)
  }

  const handleBucketUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const chosen = e.target.files?.[0]
    if (!chosen || !displayedBucketId || !projectId) return
    e.target.value = ''
    setBucketUploading(true)
    try {
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.storage.createFile({
        bucketId: displayedBucketId,
        fileId: ID.unique(),
        file: chosen,
      })
      await queryClient.refetchQueries({
        queryKey: ['files', 'project', projectId, 'bucket', displayedBucketId],
      })
      toast.success(t('File uploaded'))
    } catch (err) {
      toast.error(getErrorMessage(err))
    } finally {
      setBucketUploading(false)
    }
  }

  const handleConfirm = () => {
    if (!selectedStorageFile) return
    const accepted = onConfirm(selectedStorageFile)
    if (accepted === false) return
    reset()
    onOpenChange(false)
  }

  const canConfirm =
    !!selectedStorageFile &&
    !!displayedBucketId &&
    selectedStorageFile.bucketId === displayedBucketId &&
    !bucketUploading

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        className="!flex flex-col gap-0 p-0 max-h-[90dvh] !max-w-[min(95vw,1100px)] w-full overflow-hidden"
        style={{ width: MODAL_WIDTH, height: MODAL_HEIGHT }}
      >
        <DialogHeader className="px-6 pt-6 pb-4 text-start shrink-0">
          <DialogTitle>{t(title)}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(description)}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="flex flex-1 min-h-0 overflow-hidden">
          <div className="w-[220px] shrink-0 border-e border-border flex flex-col">
            <div className="px-3 py-1.5 border-b border-border">
              <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                {t('Buckets')}
              </p>
            </div>
            <div className="flex-1 overflow-y-auto py-1">
              {buckets.map((b) => (
                <button
                  key={b.$id}
                  type="button"
                  onClick={() => {
                    setSelectedBucketId(b.$id)
                    setSelectedStorageFile(null)
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
                <p className="px-3 py-2 text-[12px] text-muted-foreground">
                  {t('No buckets')}
                </p>
              )}
            </div>
          </div>

          <div className="flex-1 flex flex-col min-w-0 min-h-0">
            {!selectedBucketId && (
              <div className="flex-1 flex items-center justify-center text-[13px] text-muted-foreground">
                {t('Select a bucket to browse files')}
              </div>
            )}

            {selectedBucketId && (
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
                            onChange={(e) => setStorageSearch(e.target.value.trim())}
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
                            htmlFor="storage-explorer-bucket-upload"
                            className="cursor-pointer flex items-center gap-1.5"
                          >
                            <Upload className="h-3.5 w-3.5" />
                            {t('Upload')}
                          </label>
                        </Button>
                        <input
                          id="storage-explorer-bucket-upload"
                          ref={bucketUploadInputRef}
                          type="file"
                          className="sr-only"
                          onChange={handleBucketUpload}
                          disabled={bucketUploading}
                        />
                      </div>
                    </>
                  )}
                </div>

                {displayedBucketId && (
                  <div className="flex-1 min-h-0 flex flex-col overflow-hidden">
                    <div className="overflow-y-auto p-4 min-h-0 flex-1 max-h-[360px]">
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
                      ) : storageViewMode === 'list' ? (
                        <>
                          <ul className="divide-y divide-border rounded-lg border border-border bg-card overflow-hidden">
                            {files.map((f) => {
                              const isSelected =
                                selectedStorageFile?.fileId === f.$id &&
                                selectedStorageFile?.bucketId === f.bucketId
                              const FileIcon = getStorageFileIcon(f.mimeType)
                              return (
                                <li key={f.$id}>
                                  <button
                                    type="button"
                                    onClick={() =>
                                      setSelectedStorageFile({
                                        bucketId: f.bucketId,
                                        fileId: f.$id,
                                      })
                                    }
                                    className={cn(
                                      'w-full flex items-center gap-3 px-3 py-2.5 text-start text-[13px] transition-colors hover:bg-accent cursor-pointer',
                                      isSelected && 'bg-accent',
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
                                </li>
                              )
                            })}
                          </ul>
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
                      ) : (
                        <>
                          <div className="grid gap-2 sm:grid-cols-2">
                            {files.map((f) => {
                              const isSelected =
                                selectedStorageFile?.fileId === f.$id &&
                                selectedStorageFile?.bucketId === f.bucketId
                              const FileIcon = getStorageFileIcon(f.mimeType)
                              return (
                                <button
                                  key={f.$id}
                                  type="button"
                                  onClick={() =>
                                    setSelectedStorageFile({
                                      bucketId: f.bucketId,
                                      fileId: f.$id,
                                    })
                                  }
                                  className={cn(
                                    'rounded-lg border border-border bg-card p-3 text-start transition-colors hover:border-primary/40 cursor-pointer',
                                    isSelected && 'border-primary ring-1 ring-primary/20',
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
                            })}
                          </div>
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
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end shrink-0">
          <Button variant="outline" type="button" onClick={() => handleOpenChange(false)}>
            {t('Cancel')}
          </Button>
          <Button type="button" onClick={handleConfirm} disabled={!canConfirm}>
            {t(confirmLabel)}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
