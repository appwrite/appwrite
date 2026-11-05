/**
 * Import CSV modal: single file selector with file source picker (Local file + Buckets).
 * Fixed-size modal to avoid layout shifts when switching sources.
 * Only CSV-compatible files are fetched from storage (filtered by API).
 */

import { useRef, useState, useCallback, useEffect } from 'react'
import { ID } from '@appwrite.io/console'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  useCreateCSVImport,
  useProjectBuckets,
  useBucketFiles,
  useBucket,
} from '@/lib/react-query/hooks'
import { useSessionMigrations } from '@/components/global/providers/SessionMigrationsContext'
import { useQueryClient } from '@tanstack/react-query'
import { sdk, getProjectRegion } from '@/lib/appwrite/sdk'
import { toast } from 'sonner'
import {
  Upload,
  FileText,
  File,
  Image,
  List,
  LayoutGrid,
  Search,
  FileUp,
  HardDrive,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { cn } from '@/lib/utils'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { formatBytes } from '@/lib/utils/mock-data'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

const STORAGE_DOCS_URL = '/docs/products/storage'

/** Fixed modal size to prevent layout shifts when switching sources */
const MODAL_WIDTH = 1100
const MODAL_HEIGHT = 600

function getFileIcon(mimeType?: string) {
  if (mimeType?.startsWith('image/')) return Image
  return FileText
}

export interface ImportCsvProps {
  projectId: string
  databaseId: string
  tableId: string
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

/** 'local' = upload from device; string = bucketId */
type SelectedSource = 'local' | string | null

export function ImportCsv({
  projectId,
  databaseId,
  tableId,
  open,
  onOpenChange,
  onSuccess,
}: ImportCsvProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const bucketUploadInputRef = useRef<HTMLInputElement>(null)
  const [selectedSource, setSelectedSource] = useState<SelectedSource>('local')
  /** Right pane shows this bucket; only set after its files have loaded (avoids flash) */
  const [displayedBucketId, setDisplayedBucketId] = useState<string | null>(
    null,
  )
  const [file, setFile] = useState<File | null>(null)
  const [uploading, setUploading] = useState(false)
  const [bucketUploading, setBucketUploading] = useState(false)
  const [dragOver, setDragOver] = useState(false)
  const [selectedStorageFile, setSelectedStorageFile] = useState<{
    bucketId: string
    fileId: string
  } | null>(null)
  const [storageSearch, setStorageSearch] = useState('')
  const [storageViewMode, setStorageViewMode] = useState<'list' | 'grid'>(
    'list',
  )
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [displayedSearch, setDisplayedSearch] = useState('')
  const [pageSize, setPageSize] = useState(25)
  const { addImportId } = useSessionMigrations(projectId)
  const createImport = useCreateCSVImport(projectId)

  const isPending = uploading || bucketUploading || createImport.isPending
  const isLocal = selectedSource === 'local'
  const selectedBucketId =
    selectedSource && selectedSource !== 'local' ? selectedSource : null

  const { buckets } = useProjectBuckets(projectId, 0, 100)
  /** Fetch selected bucket's first page with same pageSize/search so cache matches when we switch */
  const {
    data: selectedBucketFilesData,
    isSuccess: selectedBucketFilesSuccess,
  } = useBucketFiles(
    projectId,
    selectedBucketId,
    0,
    pageSize,
    selectedBucketId ? storageSearch || undefined : undefined,
    true, // csvOnly: filter by API
  )
  /** Data for the bucket we're actually displaying (right pane) */
  const { data: bucket } = useBucket(projectId, displayedBucketId)
  /** Requested query: drives fetch for current user intent (page + search) */
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
    true, // csvOnly: filter by API
  )
  /** Displayed query: what we show; only updated when requested data is ready (no flash) */
  const { data: displayedFilesData, isLoading: displayedFilesLoading } =
    useBucketFiles(
      projectId,
      displayedBucketId,
      displayedPage - 1,
      pageSize,
      displayedBucketId ? displayedSearch || undefined : undefined,
      true, // csvOnly: filter by API
    )
  const files = (displayedFilesData?.files ?? []) as Models.File[]
  const filesTotal = displayedFilesData?.total ?? requestedFilesData?.total ?? 0

  /** Update displayed page only when requested page data is ready (no flash) */
  useEffect(() => {
    if (!filesFetching && !filesLoading && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [filesFetching, filesLoading, requestedPage, displayedPage])

  /** Update displayed search only when requested search fetch is ready (no flash) */
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

  /** When user selects a bucket, switch only after its first page has loaded (same pageSize/search = same cache) */
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
  }, [
    selectedBucketId,
    selectedBucketFilesSuccess,
    selectedBucketFilesData,
    storageSearch,
  ])

  /** On search change, request page 1; displayed search updates when fetch completes */
  useEffect(() => {
    setRequestedPage(1)
    setDisplayedPage(1)
  }, [storageSearch])

  const reset = () => {
    setFile(null)
    setSelectedStorageFile(null)
    setSelectedSource(null)
    setDisplayedBucketId(null)
    setStorageSearch('')
    setDisplayedSearch('')
    setRequestedPage(1)
    setDisplayedPage(1)
    if (fileInputRef.current) fileInputRef.current.value = ''
    if (bucketUploadInputRef.current) bucketUploadInputRef.current.value = ''
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

  /** Default to Local file when opening the modal */
  useEffect(() => {
    if (open) setSelectedSource('local')
  }, [open])

  const handleOpenChange = (next: boolean) => {
    if (!next) {
      if (!isPending) reset()
      onOpenChange(false)
    } else {
      onOpenChange(true)
    }
  }

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const chosen = e.target.files?.[0]
    if (chosen) {
      setFile(chosen)
      setSelectedStorageFile(null)
    }
    e.target.value = ''
  }

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    setDragOver(false)
    const f = e.dataTransfer.files?.[0]
    if (!f) return
    const name = f.name.toLowerCase()
    const csv =
      name.endsWith('.csv') || f.type === 'text/csv' || f.type === 'text/plain'
    if (!csv) {
      toast.error(t('Only CSV files can be imported'))
      return
    }
    setFile(f)
    setSelectedStorageFile(null)
  }, [t])

  const handleDragOver = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    setDragOver(true)
  }, [])

  const handleDragLeave = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    setDragOver(false)
  }, [])

  const handleSubmitUpload = async () => {
    if (!file || !projectId || !databaseId || !tableId) return
    setUploading(true)
    try {
      // Upload to console's default bucket (backend can read it for import).
      // Same pattern as the old Console: local file → console storage → createCSVImport(internalFile: true).
      const region = getProjectRegion(projectId) ?? 'unknown'
      const consoleSdk = sdk.forConsoleIn(region)
      const fileId = ID.unique()
      const uploaded = await consoleSdk.storage.createFile({
        bucketId: 'default',
        fileId,
        file,
      })
      const migration = await createImport.mutateAsync({
        bucketId: uploaded.bucketId ?? 'default',
        fileId: uploaded.$id,
        databaseId,
        collectionId: tableId,
        internalFile: true,
      })
      if (migration?.$id) addImportId(projectId, migration.$id)
      toast.success(t('CSV import started'))
      reset()
      onOpenChange(false)
      onSuccess?.()
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err)
      toast.error(message)
    } finally {
      setUploading(false)
    }
  }

  const handleSubmitStorage = async () => {
    if (!selectedStorageFile || !projectId || !databaseId || !tableId) return
    try {
      const migration = await createImport.mutateAsync({
        bucketId: selectedStorageFile.bucketId,
        fileId: selectedStorageFile.fileId,
        databaseId,
        collectionId: tableId,
        internalFile: false,
      })
      if (migration?.$id) addImportId(projectId, migration.$id)
      reset()
      onOpenChange(false)
      onSuccess?.()
    } catch (err) {
      const message =
        err instanceof Error ? err.message : t('Failed to start CSV import')
      toast.error(message)
    }
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
      toast.error(err instanceof Error ? err.message : t('Upload failed'))
    } finally {
      setBucketUploading(false)
    }
  }

  const canImport =
    !isPending &&
    ((isLocal && !!file) ||
      (!!displayedBucketId &&
        !!selectedStorageFile &&
        selectedStorageFile.bucketId === displayedBucketId))

  const handleImport = () => {
    if (isLocal) void handleSubmitUpload()
    else void handleSubmitStorage()
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        className="!flex flex-col gap-0 p-0 max-h-[90dvh] !max-w-[min(95vw,1100px)] w-full overflow-hidden"
        style={{ width: MODAL_WIDTH, height: MODAL_HEIGHT }}
      >
        <DialogHeader className="px-6 pt-6 pb-4 text-start shrink-0">
          <DialogTitle>{t('Import CSV')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Choose a CSV file from your device or from a storage bucket. Only CSV files can be imported.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="flex flex-1 min-h-0 overflow-hidden">
          {/* Left: sources (Local file + Buckets) */}
          <div className="w-[220px] shrink-0 border-e border-border flex flex-col">
            <div className="py-2">
              <button
                type="button"
                onClick={() => {
                  setSelectedSource('local')
                  setDisplayedBucketId(null)
                  setSelectedStorageFile(null)
                }}
                className={cn(
                  'w-full flex items-center gap-2 px-3 py-2.5 text-start text-[13px] transition-colors',
                  isLocal
                    ? 'bg-accent text-accent-foreground font-medium'
                    : 'text-foreground hover:bg-muted/50',
                )}
              >
                <HardDrive className="h-4 w-4 shrink-0" />
                <span className="truncate">{t('Local file')}</span>
              </button>
            </div>
            <div className="border-t border-border my-1" />
            <div className="px-3 py-1.5">
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
                    setSelectedSource(b.$id)
                    setSelectedStorageFile(null)
                    setFile(null)
                    setStorageSearch('')
                    if (fileInputRef.current) fileInputRef.current.value = ''
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

          {/* Right: content (fixed area to avoid layout shift); min-h-0 so column shrinks and inner area scrolls */}
          <div className="flex-1 flex flex-col min-w-0 min-h-0">
            {!selectedSource && (
              <div className="flex-1 flex items-center justify-center text-[13px] text-muted-foreground">
                {t('Select a source to continue')}
              </div>
            )}

            {isLocal && (
              <div className="flex-1 flex items-center justify-center p-6 min-h-0">
                <input
                  ref={fileInputRef}
                  type="file"
                  accept=".csv,text/csv,text/plain"
                  className="sr-only"
                  id="import-csv-file"
                  onChange={handleFileChange}
                />
                <div
                  onDrop={handleDrop}
                  onDragOver={handleDragOver}
                  onDragLeave={handleDragLeave}
                  className={cn(
                    'w-full max-w-md rounded-xl border-2 border-dashed p-8 text-center transition-colors',
                    dragOver
                      ? 'border-primary bg-primary/5'
                      : 'border-border hover:border-muted-foreground/40',
                  )}
                >
                  <FileUp className="mx-auto h-10 w-10 text-muted-foreground" />
                  <p className="mt-3 text-[13px] font-medium text-foreground">
                    {t('Drag and drop a CSV file here, or choose from your computer')}
                  </p>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="mt-3"
                    onClick={() => fileInputRef.current?.click()}
                    disabled={isPending}
                  >
                    {t('Choose file')}
                  </Button>
                  {file && (
                    <p className="mt-3 text-[12px] text-muted-foreground">
                      {t('Selected:')} {file.name} ({formatBytes(file.size)})
                    </p>
                  )}
                </div>
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
                            onChange={(e) =>
                              setStorageSearch(e.target.value.trim())
                            }
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
                            htmlFor="import-csv-bucket-upload"
                            className="cursor-pointer flex items-center gap-1.5"
                          >
                            <Upload className="h-3.5 w-3.5" />
                            {t('Upload')}
                          </label>
                        </Button>
                        <input
                          id="import-csv-bucket-upload"
                          ref={bucketUploadInputRef}
                          type="file"
                          accept=".csv,text/csv,text/plain"
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
                        <EmptyState variant="card" className="py-12">
                          <div className="flex flex-col items-center text-center">
                            <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                              <File className="h-6 w-6 text-muted-foreground" />
                            </div>
                            <p className="mb-1 text-[14px] font-medium text-foreground">
                              {storageSearch
                                ? t('No CSV files match your search')
                                : t('No CSV files in this bucket')}
                            </p>
                            <p className="mb-4 text-[13px] text-muted-foreground">
                              {storageSearch
                                ? t('Try a different search or upload a CSV file.')
                                : t('Upload a CSV file to this bucket or choose another bucket.')}
                            </p>
                            <div className="flex gap-2">
                              <Button variant="outline" size="sm" asChild>
                                <DocsRouteLink href={STORAGE_DOCS_URL}>
                                  {t('Documentation')}
                                </DocsRouteLink>
                              </Button>
                              <Button
                                size="sm"
                                asChild
                                disabled={bucketUploading}
                              >
                                <label
                                  htmlFor="import-csv-bucket-upload"
                                  className="cursor-pointer"
                                >
                                  {t('Upload file')}
                                </label>
                              </Button>
                            </div>
                          </div>
                        </EmptyState>
                      ) : storageViewMode === 'list' ? (
                        <>
                          <ul className="divide-y divide-border rounded-lg border border-border bg-card overflow-hidden">
                            {files.map((f) => {
                              const isSelected =
                                selectedStorageFile?.fileId === f.$id &&
                                selectedStorageFile?.bucketId === f.bucketId
                              const FileIcon = getFileIcon(f.mimeType)
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
                            itemLabel={t('files')}
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
                              const FileIcon = getFileIcon(f.mimeType)
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
                                    isSelected &&
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
                            })}
                          </div>
                          <Pagination
                            currentPage={displayedPage}
                            totalItems={filesTotal}
                            pageSize={pageSize}
                            pageSizeOptions={[10, 25, 50, 100]}
                            onPageChange={handleStoragePageChange}
                            onPageSizeChange={handleStoragePageSizeChange}
                            itemLabel={t('files')}
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
          <Button
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={isPending}
          >
            {t('Cancel')}
          </Button>
          <Button onClick={handleImport} disabled={!canImport}>
            {t('Import')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
