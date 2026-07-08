import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { ID, Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { ImageIcon, Upload, X } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { cn } from '@/lib/utils'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  APPS_LOGO_BUCKET_ID,
  buildAppLogoFilePermissions,
  getAppLogoFilePreviewUrl,
  getAppsLogoConsoleStorageSdk,
  parseAppLogoFileId,
  resolveAppsLogoConsoleRegion,
} from '@/lib/appwrite/apps-logo'
import { useProjectsForTeam } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'

const PAGE_SIZE = 25

interface AppLogoFilePickerProps {
  teamId: string
  value: string
  onChange: (logoUri: string) => void
  disabled?: boolean
}

export function AppLogoFilePicker({
  teamId,
  value,
  onChange,
  disabled = false,
}: AppLogoFilePickerProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const uploadInputRef = useRef<HTMLInputElement>(null)
  const { projects } = useProjectsForTeam(teamId, 0, 1)
  const consoleRegion = resolveAppsLogoConsoleRegion(projects[0]?.region)
  const consoleStorageSdk = useMemo(
    () => getAppsLogoConsoleStorageSdk(consoleRegion),
    [consoleRegion],
  )
  const [explorerOpen, setExplorerOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [selectedFileId, setSelectedFileId] = useState<string | null>(null)
  const [files, setFiles] = useState<Models.File[]>([])
  const [filesTotal, setFilesTotal] = useState(0)
  const [filesLoading, setFilesLoading] = useState(false)
  const [uploading, setUploading] = useState(false)

  const selectedFromValue = useMemo(() => parseAppLogoFileId(value), [value])

  const previewUrl = useMemo(() => {
    if (!value.trim()) return null
    if (selectedFromValue) {
      return getAppLogoFilePreviewUrl(selectedFromValue, {
        width: 128,
        height: 128,
        region: consoleRegion,
      })
    }
    return value
  }, [selectedFromValue, value, consoleRegion])

  const loadFiles = useCallback(async () => {
    setFilesLoading(true)
    try {
      const response = await consoleStorageSdk.storage.listFiles({
        bucketId: APPS_LOGO_BUCKET_ID,
        queries: [
          Query.orderDesc('$createdAt'),
          Query.endsWith('name', '.png'),
          Query.limit(PAGE_SIZE),
          Query.offset((page - 1) * PAGE_SIZE),
        ],
        search: search || undefined,
      })
      setFiles(response.files ?? [])
      setFilesTotal(response.total ?? 0)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to load logo files')))
      setFiles([])
      setFilesTotal(0)
    } finally {
      setFilesLoading(false)
    }
  }, [consoleStorageSdk, page, search, t])

  useEffect(() => {
    if (!explorerOpen) return
    void loadFiles()
  }, [explorerOpen, loadFiles])

  useEffect(() => {
    if (!explorerOpen) return
    setSelectedFileId(selectedFromValue)
  }, [explorerOpen, selectedFromValue])

  const resetExplorer = () => {
    setSearch('')
    setPage(1)
    setSelectedFileId(null)
    if (uploadInputRef.current) uploadInputRef.current.value = ''
  }

  const handleExplorerOpenChange = (open: boolean) => {
    if (!open) resetExplorer()
    setExplorerOpen(open)
  }

  const handleUpload = async (
    event: React.ChangeEvent<HTMLInputElement>,
    options?: { applyImmediately?: boolean; closeExplorer?: boolean },
  ) => {
    const file = event.target.files?.[0]
    if (!file) return
    event.target.value = ''

    if (!file.name.toLowerCase().endsWith('.png')) {
      toast.error(t('Only PNG logos are supported'))
      return
    }

    setUploading(true)
    try {
      const uploaded = await consoleStorageSdk.storage.createFile({
        bucketId: APPS_LOGO_BUCKET_ID,
        fileId: ID.unique(),
        file,
        permissions: buildAppLogoFilePermissions(teamId),
      })

      const previewUrl = getAppLogoFilePreviewUrl(uploaded.$id, {
        width: 256,
        height: 256,
        region: consoleRegion,
      })

      await queryClient.invalidateQueries({
        queryKey: ['apps', 'logo-files', APPS_LOGO_BUCKET_ID],
      })

      setSelectedFileId(uploaded.$id)
      setPage(1)
      await loadFiles()

      if (options?.applyImmediately) {
        onChange(previewUrl)
        if (options.closeExplorer) {
          handleExplorerOpenChange(false)
        }
      }

      toast.success(t('Logo uploaded'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to upload logo')))
    } finally {
      setUploading(false)
    }
  }

  const handleConfirmSelection = () => {
    if (!selectedFileId) return
    onChange(
      getAppLogoFilePreviewUrl(selectedFileId, {
        width: 256,
        height: 256,
        region: consoleRegion,
      }),
    )
    handleExplorerOpenChange(false)
  }

  return (
    <div className="space-y-3">
      <div className="rounded-lg border border-dashed border-border bg-muted/20 p-4">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
          <div className="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-card">
            {previewUrl ? (
              <img
                src={previewUrl}
                alt={t('App logo preview')}
                className="h-full w-full object-contain"
              />
            ) : (
              <ImageIcon className="h-8 w-8 text-muted-foreground" />
            )}
          </div>

          <div className="min-w-0 flex-1 space-y-2">
            <p className="text-[13px] text-muted-foreground">
              {t(
                'Upload a PNG logo or pick an existing file from the Apps bucket. Logos are publicly readable and writable by your team.',
              )}
            </p>
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={disabled || uploading}
                onClick={() => setExplorerOpen(true)}
              >
                {t('Browse files')}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={disabled || uploading}
                asChild
              >
                <label className="cursor-pointer">
                  <Upload className="me-1.5 h-3.5 w-3.5" />
                  {t('Upload PNG')}
                  <input
                    ref={uploadInputRef}
                    type="file"
                    accept="image/png,.png"
                    className="sr-only"
                    disabled={disabled || uploading}
                    onChange={(event) =>
                      handleUpload(event, { applyImmediately: true })
                    }
                  />
                </label>
              </Button>
              {value ? (
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  disabled={disabled}
                  onClick={() => onChange('')}
                >
                  <X className="me-1.5 h-3.5 w-3.5" />
                  {t('Remove')}
                </Button>
              ) : null}
            </div>
          </div>
        </div>
      </div>

      <Dialog open={explorerOpen} onOpenChange={handleExplorerOpenChange}>
        <DialogContent className="flex max-h-[90dvh] flex-col gap-0 p-0 sm:max-w-2xl">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Select app logo')}</DialogTitle>
            <DialogDescription className="mt-2 text-[13px]">
              {t('Choose a PNG from the Apps bucket or upload a new one.')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />

          <div className="space-y-3 px-6 py-4">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
              <Input
                value={search}
                onChange={(event) => {
                  setSearch(event.target.value.trim())
                  setPage(1)
                }}
                placeholder={t('Search files...')}
                className="h-8 text-[13px]"
              />
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={uploading}
                asChild
              >
                <label className="cursor-pointer">
                  <Upload className="me-1.5 h-3.5 w-3.5" />
                  {t('Upload PNG')}
                  <input
                    type="file"
                    accept="image/png,.png"
                    className="sr-only"
                    disabled={uploading}
                    onChange={(event) =>
                      handleUpload(event, {
                        applyImmediately: true,
                        closeExplorer: true,
                      })
                    }
                  />
                </label>
              </Button>
            </div>

            <div className="max-h-[360px] overflow-y-auto">
              {filesLoading && files.length === 0 ? (
                <div className="py-10 text-center text-[13px] text-muted-foreground">
                  {t('Loading files…')}
                </div>
              ) : files.length === 0 ? (
                <EmptyState
                  icon={ImageIcon}
                  title={
                    search
                      ? t('No files match your search')
                      : t('No logo files yet')
                  }
                  description={t('Upload a PNG logo to get started.')}
                  variant="card"
                  iconSize="md"
                />
              ) : (
                <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-card">
                  {files.map((file) => {
                    const isSelected = selectedFileId === file.$id
                    const thumbUrl = getAppLogoFilePreviewUrl(file.$id, {
                      width: 64,
                      height: 64,
                      region: consoleRegion,
                    })
                    return (
                      <li key={file.$id}>
                        <button
                          type="button"
                          onClick={() => setSelectedFileId(file.$id)}
                          className={cn(
                            'flex w-full items-center gap-3 px-3 py-2.5 text-start text-[13px] transition-colors hover:bg-accent',
                            isSelected && 'bg-accent',
                          )}
                        >
                          <img
                            src={thumbUrl}
                            alt={file.name}
                            className="h-10 w-10 rounded-md border border-border object-contain bg-card"
                          />
                          <div className="min-w-0 flex-1">
                            <p className="truncate font-medium text-foreground">
                              {file.name}
                            </p>
                            <p className="text-[11px] text-muted-foreground">
                              {file.$id}
                            </p>
                          </div>
                        </button>
                      </li>
                    )
                  })}
                </ul>
              )}
            </div>

            {filesTotal > 0 ? (
              <Pagination
                currentPage={page}
                totalItems={filesTotal}
                pageSize={PAGE_SIZE}
                pageSizeOptions={[PAGE_SIZE]}
                onPageChange={setPage}
                onPageSizeChange={() => undefined}
                itemLabel={t('files')}
              />
            ) : null}
          </div>

          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleExplorerOpenChange(false)}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              disabled={!selectedFileId || uploading}
              onClick={handleConfirmSelection}
            >
              {t('Select logo')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
