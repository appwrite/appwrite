import { useMemo, useState, useEffect, useCallback, type CSSProperties, type SyntheticEvent } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import {
  AlertCircle,
  Download,
  ExternalLink,
  FileText,
  Link2,
  PanelRight,
  Trash2,
  Wand2,
  X,
} from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { useAvifSupport } from '@/lib/avif-support'
import {
  useFile,
  useBucket,
  useProject,
  useOrganizationScopes,
  Dependencies,
  removeCachedFile,
} from '@/lib/react-query/hooks'
import { canShowBucketSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  getStorageFileIcon,
  isStoragePreviewSupportedMimeType,
  isStorageVideoPreviewSupportedMimeType,
} from '@/components/global/shared/StorageFilePreviewThumb'
import { formatBytes } from '@/lib/utils/mock-data'
import {
  STORAGE_FILES_PREVIEW_HEADER_ROW_CLASS,
  STORAGE_FILES_SPLIT_PANE_BG_CLASS,
} from './files-documents-layout'
import { FileSecurity } from './FileSecurity'
import { TransformImageWizard } from './TransformImageWizard'
import {
  buildAdminFileViewUrl,
  buildAdminStorageInspectorPreviewUrl,
  getStorageInspectorPreviewBaseWidthPx,
  getStorageInspectorPreviewWidthFromBasePx,
} from './transform-image-wizard-state'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { useT } from '@/lib/i18n/translate'

/** Layout box for inspector preview; `object-contain` preserves aspect ratio */
const INSPECTOR_PREVIEW_MAX_WIDTH_CSS = 'min(100%, 28rem)'
const INSPECTOR_PREVIEW_MAX_HEIGHT_CSS = 'min(50dvh, 32rem)'

export type FileInspectorPanelProps = {
  projectId: string
  bucketId: string
  /** Selected file from `?file=` or row selection */
  fileId: string | undefined
  /** URL `filePanel` - `security` is kept for older links and opens Permissions */
  panelTab?: 'overview' | 'permissions' | 'tokens' | 'security'
  /** Inline stacked split: dismiss preview and clear `?file=` */
  onClose?: () => void
  /** `drawer` = bottom sheet on narrow viewports (close is on the drawer chrome). */
  presentation?: 'inline' | 'drawer'
}

type InspectorTab = 'overview' | 'permissions' | 'tokens'

/**
 * Right-hand inspector for Storage files workspace: overview, permissions,
 * file tokens, download/preview/delete - intended to replace the standalone file page.
 */
export function FileInspectorPanel({
  projectId,
  bucketId,
  fileId,
  panelTab,
  onClose,
  presentation = 'inline',
}: FileInspectorPanelProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const avifSupported = useAvifSupport()
  const { data: file, isLoading, isError } = useFile(projectId, bucketId, fileId)
  const { data: bucket } = useBucket(projectId, bucketId)
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSecurityTab = canShowBucketSecuritySettings(access, features)

  const [videoPlaybackError, setVideoPlaybackError] = useState(false)

  const [imageLoaded, setImageLoaded] = useState(false)
  const [previewIntrinsicPx, setPreviewIntrinsicPx] = useState<{
    w: number
    h: number
    /** Device pixel ratio when the bitmap decoded - caps CSS px so ~1 bitmap px maps to ~1 device px on retina. */
    dpr: number
  } | null>(null)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [transformWizardOpen, setTransformWizardOpen] = useState(false)

  const inspectorPreviewWidthPx = useMemo(
    () => getStorageInspectorPreviewBaseWidthPx(),
    [],
  )

  const inspectorTab: InspectorTab = useMemo(() => {
    if (!showSecurityTab) return 'overview'
    if (panelTab === 'permissions' || panelTab === 'tokens') return panelTab
    if (panelTab === 'security') return 'permissions'
    return 'overview'
  }, [showSecurityTab, panelTab])

  const inspectorPreviewRequestWidthPx = useMemo(() => {
    return getStorageInspectorPreviewWidthFromBasePx(
      inspectorPreviewWidthPx,
      file?.sizeOriginal,
    )
  }, [inspectorPreviewWidthPx, file?.sizeOriginal])

  const previewUrl = useMemo(() => {
    if (!file || !projectId || !bucketId) return null
    if (!isStoragePreviewSupportedMimeType(file.mimeType)) return null
    return buildAdminStorageInspectorPreviewUrl(
      projectId,
      bucketId,
      file.$id,
      {
        preferAvif: avifSupported,
        initialPreviewRequestWidthPx: inspectorPreviewRequestWidthPx,
        originalSizeBytes: file.sizeOriginal,
      },
    )
  }, [
    file,
    projectId,
    bucketId,
    avifSupported,
    inspectorPreviewRequestWidthPx,
  ])

  const videoSourceUrl = useMemo(() => {
    if (!file || !projectId || !bucketId) return null
    if (!isStorageVideoPreviewSupportedMimeType(file.mimeType)) return null
    return buildAdminFileViewUrl(projectId, bucketId, file.$id)
  }, [file, projectId, bucketId])

  useEffect(() => {
    setImageLoaded(false)
    setPreviewIntrinsicPx(null)
    setVideoPlaybackError(false)
  }, [previewUrl, videoSourceUrl])

  const onVideoLoadedMetadata = useCallback(
    (e: SyntheticEvent<HTMLVideoElement>) => {
      const v = e.currentTarget
      const reveal = () => setImageLoaded(true)
      const d = v.duration
      if (!Number.isFinite(d) || d <= 0) return

      const t = Math.min(0.05, Math.max(d / 1000, 1e-5), d * 0.99)
      let revealed = false
      const safeReveal = () => {
        if (revealed) return
        revealed = true
        reveal()
      }

      let seekFallbackId: ReturnType<typeof setTimeout> | undefined
      const onSeeked = () => {
        v.removeEventListener('seeked', onSeeked)
        if (seekFallbackId !== undefined) window.clearTimeout(seekFallbackId)
        safeReveal()
      }

      v.addEventListener('seeked', onSeeked, { once: true })
      seekFallbackId = window.setTimeout(() => {
        v.removeEventListener('seeked', onSeeked)
        safeReveal()
      }, 1500)

      try {
        v.currentTime = t
      } catch {
        if (seekFallbackId !== undefined) window.clearTimeout(seekFallbackId)
        safeReveal()
      }
    },
    [],
  )

  const onVideoLoadedData = useCallback(
    (e: SyntheticEvent<HTMLVideoElement>) => {
      const v = e.currentTarget
      if (!Number.isFinite(v.duration) || v.duration <= 0) {
        setImageLoaded(true)
      }
    },
    [],
  )

  const inspectorPreviewImageMaxStyle = useMemo(():
    | CSSProperties
    | undefined => {
    if (!previewIntrinsicPx) return undefined
    const r = Math.max(1, previewIntrinsicPx.dpr)
    const cssMaxW = previewIntrinsicPx.w / r
    const cssMaxH = previewIntrinsicPx.h / r
    return {
      maxWidth: `min(${INSPECTOR_PREVIEW_MAX_WIDTH_CSS}, ${cssMaxW}px)`,
      maxHeight: `min(${INSPECTOR_PREVIEW_MAX_HEIGHT_CSS}, ${cssMaxH}px)`,
    }
  }, [previewIntrinsicPx])

  const deleteFileMutation = useMutation({
    mutationFn: async (targetFileId: string) => {
      if (!projectId || !bucketId || !targetFileId) {
        throw new Error('Project ID, Bucket ID, and File ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.deleteFile({
        bucketId,
        fileId: targetFileId,
      })
    },
    onSuccess: async (_data, targetFileId) => {
      removeCachedFile(queryClient, projectId!, bucketId!, targetFileId)
      await queryClient.refetchQueries({ queryKey: Dependencies.FILES })
      toast.success(t('File has been deleted'))
      setDeleteDialogOpen(false)
      navigate({
        to: '/projects/$projectId/storage/$bucketId',
        params: { projectId, bucketId },
        search: (prev: Record<string, unknown>) => {
          const next = { ...prev }
          delete next.file
          delete next.filePanel
          return next
        },
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })

  const handleDownload = () => {
    if (!projectId || !bucketId || !file) return
    const projectSdk = sdk.forProject(projectId)
    const url = projectSdk.storage.getFileDownload({
      bucketId,
      fileId: file.$id,
    })
    const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
    window.open(urlWithMode, '_blank')
  }

  const handlePreview = () => {
    if (!projectId || !bucketId || !file) return
    const projectSdk = sdk.forProject(projectId)
    const raw = file.mimeType?.toLowerCase().startsWith('video/')
      ? projectSdk.storage.getFileView({ bucketId, fileId: file.$id })
      : projectSdk.storage.getFilePreview({ bucketId, fileId: file.$id })
    const urlWithMode = raw + (raw.includes('?') ? '&' : '?') + 'mode=admin'
    window.open(urlWithMode, '_blank')
  }

  const handleCopyFileViewUrl = () => {
    if (!projectId || !bucketId) return
    const idForClipboard = file?.$id ?? fileId
    if (!idForClipboard) return
    void copyToClipboard(
      'File view URL',
      buildAdminFileViewUrl(projectId, bucketId, idForClipboard),
    )
  }

  const closePreviewButton =
    onClose && presentation === 'inline' ? (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      className="h-6 w-6"
      onClick={onClose}
      aria-label={t('Close file preview')}
    >
      <X className="h-3.5 w-3.5" />
    </Button>
  ) : null

  if (!fileId) {
    return (
      <aside
        className={cn(
          'flex h-full min-h-0 w-full min-w-0 flex-col',
          STORAGE_FILES_SPLIT_PANE_BG_CLASS,
        )}
      >
        <div className={STORAGE_FILES_PREVIEW_HEADER_ROW_CLASS}>
          <PanelRight className="h-4 w-4 text-muted-foreground" />
          <span className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('File')}
          </span>
        </div>
        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-5 py-8 text-center">
          <FileText className="h-10 w-10 text-muted-foreground/45" />
          <div className="space-y-1.5">
            <p className="text-[14px] font-medium text-foreground">
              {t('No file selected')}
            </p>
            <p className="max-w-sm text-[13px] leading-relaxed text-muted-foreground">
              {t(
                'Select a row in the table to view preview and metadata, or use Create file in the header to upload.',
              )}
            </p>
          </div>
        </div>
      </aside>
    )
  }

  if (!file) {
    if (isLoading) {
      return (
        <TooltipProvider delayDuration={0}>
          <aside
        className={cn(
          'flex h-full min-h-0 w-full min-w-0 flex-col',
          STORAGE_FILES_SPLIT_PANE_BG_CLASS,
        )}
      >
            <div
              className={cn(
                STORAGE_FILES_PREVIEW_HEADER_ROW_CLASS,
                'justify-between',
              )}
            >
              <div className="flex items-center gap-2">
                <PanelRight className="h-4 w-4 text-muted-foreground" />
                <span className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('File')}
                </span>
              </div>
              <Tooltip>
                <TooltipTrigger asChild>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-6 w-6"
                    onClick={handleCopyFileViewUrl}
                    aria-label={t('Copy file view URL')}
                  >
                    <Link2 className="h-3.5 w-3.5" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent side="bottom" className="text-xs">
                  {t('Copy view URL')}
                </TooltipContent>
              </Tooltip>
              {closePreviewButton}
            </div>
            <div className="flex flex-1 items-center justify-center px-4">
              <p className="text-[12px] text-muted-foreground">
                {t('Loading file…')}
              </p>
            </div>
          </aside>
        </TooltipProvider>
      )
    }
    return (
      <TooltipProvider delayDuration={0}>
        <aside
        className={cn(
          'flex h-full min-h-0 w-full min-w-0 flex-col',
          STORAGE_FILES_SPLIT_PANE_BG_CLASS,
        )}
      >
          <div
            className={cn(
              STORAGE_FILES_PREVIEW_HEADER_ROW_CLASS,
              'justify-between',
            )}
          >
            <div className="flex items-center gap-2">
              <PanelRight className="h-4 w-4 text-muted-foreground" />
              <span className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('File')}
              </span>
            </div>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-6 w-6"
                  onClick={handleCopyFileViewUrl}
                  aria-label={t('Copy file view URL')}
                >
                  <Link2 className="h-3.5 w-3.5" />
                </Button>
              </TooltipTrigger>
              <TooltipContent side="bottom" className="text-xs">
                {t('Copy view URL')}
              </TooltipContent>
            </Tooltip>
            {closePreviewButton}
          </div>
          <div className="flex flex-1 items-center justify-center px-4">
            <p className="text-[12px] text-muted-foreground">
              {isError ? t('Could not load file') : t('File not found')}
            </p>
          </div>
        </aside>
      </TooltipProvider>
    )
  }

  const isPending =
    file.chunksTotal > 0 && file.chunksUploaded < file.chunksTotal

  const compressionLabel =
    file.compression === 'none' || !file.compression
      ? t('None')
      : file.compression === 'gzip'
        ? 'Gzip'
        : file.compression === 'zstd'
          ? 'Zstd'
          : file.compression.charAt(0).toUpperCase() + file.compression.slice(1)

  const PreviewPlaceholderIcon = getStorageFileIcon(file.mimeType)

  /** Match tab strip: `TabsList` wrapper uses the same horizontal + vertical padding */
  const inspectorTabContentClass =
    'space-y-4 px-3 pb-6 pt-3 sm:px-6 sm:pb-8 sm:pt-4'

  const overviewBody = (
    <div className={inspectorTabContentClass}>
      {isPending ? (
        <Badge variant="warning" className="text-[10px] shrink-0">
          {t('Pending upload')}
        </Badge>
      ) : null}

      {!isPending && videoSourceUrl ? (
        <div className="overflow-hidden rounded-lg border border-border/50 bg-black">
          <div className="flex w-full justify-center bg-black p-1.5 sm:p-2">
            <div
              className={cn(
                'relative flex aspect-video w-[min(100%,28rem,calc(min(50dvh,32rem)*16/9))] max-w-full shrink-0 items-center justify-center overflow-hidden rounded-md bg-black',
              )}
            >
              {videoPlaybackError ? (
                <div className="flex h-full min-h-0 w-full flex-col items-center justify-center gap-2 px-4 py-6 text-center">
                  <PreviewPlaceholderIcon className="h-10 w-10 shrink-0 text-muted-foreground/70" />
                  <p className="max-w-[240px] text-[12px] leading-snug text-muted-foreground">
                    {t(
                      'This video could not be played inline. Try Open preview or Download.',
                    )}
                  </p>
                </div>
              ) : (
                <video
                  key={videoSourceUrl}
                  className={cn(
                    'h-full w-full max-w-full object-contain outline-none',
                    'shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)]',
                    'transition-opacity duration-300',
                    imageLoaded ? 'opacity-100' : 'opacity-0',
                  )}
                  controls
                  playsInline
                  preload="auto"
                  src={videoSourceUrl}
                  aria-label={`${t('Video preview:')} ${file.name}`}
                  onLoadedMetadata={onVideoLoadedMetadata}
                  onLoadedData={onVideoLoadedData}
                  onError={() => setVideoPlaybackError(true)}
                />
              )}
            </div>
          </div>
        </div>
      ) : null}

      {!isPending && previewUrl ? (
        <div className="overflow-hidden rounded-lg border border-border bg-muted/20">
          <div className="flex w-full justify-center p-1.5 sm:p-2">
            <div
              className={cn(
                'relative flex aspect-video w-[min(100%,28rem,calc(min(50dvh,32rem)*16/9))] max-w-full shrink-0 items-center justify-center overflow-hidden rounded-md bg-muted/30',
              )}
            >
              <img
                src={previewUrl}
                alt={file.name}
                decoding="async"
                onLoad={(e) => {
                  const el = e.currentTarget
                  const dpr =
                    typeof window !== 'undefined'
                      ? Math.max(1, window.devicePixelRatio || 1)
                      : 1
                  if (el.naturalWidth > 0 && el.naturalHeight > 0) {
                    setPreviewIntrinsicPx({
                      w: el.naturalWidth,
                      h: el.naturalHeight,
                      dpr,
                    })
                  }
                  setImageLoaded(true)
                }}
                className={cn(
                  'h-full w-full max-h-full max-w-full object-contain [image-rendering:auto]',
                  'transition-opacity duration-300',
                  imageLoaded ? 'opacity-100' : 'opacity-0',
                )}
                style={inspectorPreviewImageMaxStyle}
              />
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="absolute bottom-2 start-2 z-10 h-8 gap-1.5 bg-background/90 text-[13px] opacity-60 shadow-sm backdrop-blur-sm transition-opacity hover:opacity-100 sm:bottom-3 sm:start-3"
                onClick={() => setTransformWizardOpen(true)}
              >
                <Wand2 className="h-3.5 w-3.5 shrink-0" />
                {t('Transform')}
              </Button>
            </div>
          </div>
        </div>
      ) : null}

      <div>
        <p className="truncate text-[14px] font-semibold text-foreground">
          {file.name}
        </p>
        <div className="mt-2">
          <CopyableId id={file.$id} size="sm" />
        </div>
      </div>

      <div className="border-t border-border" />

      <div className="space-y-3">
        <div>
          <Label className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
            {t('MIME type')}
          </Label>
          <p className="mt-1 break-all font-mono text-[12px] text-foreground/90">
            {file.mimeType || '-'}
          </p>
        </div>
        <div>
          <Label className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
            {t('Size')}
          </Label>
          <p className="mt-1 font-mono text-[12px] text-foreground/90">
            {formatBytes(file.sizeOriginal)}
          </p>
        </div>
        <div className="grid grid-cols-1 gap-3">
          <div>
            <Label className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              {t('Created')}
            </Label>
            <div className="mt-1">
              <DateTooltip
                date={new Date(file.$createdAt)}
                className="text-[12px] text-muted-foreground"
              />
            </div>
          </div>
          <div>
            <Label className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              {t('Updated')}
            </Label>
            <div className="mt-1">
              <DateTooltip
                date={new Date(file.$updatedAt)}
                className="text-[12px] text-muted-foreground"
              />
            </div>
          </div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              {t('Encryption')}
            </Label>
            <p className="mt-1 text-[12px] text-muted-foreground">
              {file.encryption === true ? t('Enabled') : t('Disabled')}
            </p>
          </div>
          <div>
            <Label className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              {t('Compression')}
            </Label>
            <p className="mt-1 text-[12px] text-muted-foreground">
              {compressionLabel}
            </p>
          </div>
        </div>
        {file.signature ? (
          <div>
            <Label className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              {t('MD5 signature')}
            </Label>
            <div className="mt-1">
              <CopyableId id={file.signature} size="sm" maxWidth={200} />
            </div>
          </div>
        ) : null}
        {isPending ? (
          <div>
            <Label className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              {t('Upload progress')}
            </Label>
            <p className="mt-1 text-[12px] text-muted-foreground">
              {file.chunksUploaded} {t('of')} {file.chunksTotal}{' '}
              {t('chunks uploaded')}
            </p>
          </div>
        ) : null}
      </div>

      <div className="rounded-lg border border-red-500/30 bg-card/50 overflow-hidden">
        <div className="border-b border-red-500/20 px-4 py-3">
          <h3 className="text-[14px] font-semibold text-red-600 dark:text-red-400">
            {t('Delete file')}
          </h3>
          <p className="mt-1 text-[12px] text-muted-foreground">
            {t('Permanently delete this file. This action cannot be undone.')}
          </p>
        </div>
        <div className="px-4 py-3">
          <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
            <DialogTrigger asChild>
              <Button
                variant="destructive"
                size="sm"
                className="h-8 text-[12px]"
              >
                <Trash2 className="me-1.5 h-3.5 w-3.5" />
                {t('Delete')}
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md p-0">
              <DialogHeader className="px-6 pt-6 text-start">
                <DialogTitle>{t('Delete file')}</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  {t('Are you sure you want to delete')}{' '}
                  <strong>{file.name}</strong>?{' '}
                  {t('This action cannot be undone.')}
                </DialogDescription>
              </DialogHeader>
              <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => setDeleteDialogOpen(false)}
                  disabled={deleteFileMutation.isPending}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={deleteFileMutation.isPending}
                  onClick={() => deleteFileMutation.mutate(file.$id)}
                >
                  {t('Delete')}
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        </div>
      </div>
    </div>
  )

  const permissionsTabBody = (
    <div className={cn('min-h-0', inspectorTabContentClass)}>
      {bucket && !bucket.fileSecurity ? (
        <Alert variant="default" className="border-amber-500/30 bg-amber-500/5">
          <AlertCircle className="h-4 w-4 text-amber-500" />
          <AlertTitle className="text-[12px] font-medium text-amber-600 dark:text-amber-400">
            {t('File level security is disabled')}
          </AlertTitle>
          <AlertDescription className="text-[11px] text-amber-600/80 dark:text-amber-400/80">
            {t(
              'File-level permissions only apply when file level security is enabled on the bucket.',
            )}{' '}
            <span className="whitespace-nowrap">
              <Link
                to="/projects/$projectId/storage/$bucketId/security"
                params={{ projectId, bucketId }}
                className="font-medium underline hover:no-underline"
              >
                {t('Enable in bucket Security')}
              </Link>
              .
            </span>
          </AlertDescription>
        </Alert>
      ) : null}
      <FileSecurity
        key={file.$id}
        projectId={projectId}
        bucketId={bucketId}
        fileId={file.$id}
        variant="panel"
        panelSection="permissions"
      />
    </div>
  )

  const tokensTabBody = (
    <div className={cn('min-h-0', inspectorTabContentClass)}>
      <FileSecurity
        key={file.$id}
        projectId={projectId}
        bucketId={bucketId}
        fileId={file.$id}
        variant="panel"
        panelSection="tokens"
      />
    </div>
  )

  return (
    <>
      <TooltipProvider delayDuration={0}>
      <aside
        className={cn(
          'flex h-full min-h-0 w-full min-w-0 flex-col',
          STORAGE_FILES_SPLIT_PANE_BG_CLASS,
        )}
      >
        <div
          className={cn(
            STORAGE_FILES_PREVIEW_HEADER_ROW_CLASS,
            'justify-between gap-1',
          )}
        >
          <div className="flex min-w-0 flex-1 items-center gap-2">
            <PanelRight className="h-4 w-4 shrink-0 text-muted-foreground" />
            <span className="truncate text-start text-[12px] font-medium leading-none text-foreground">
              {file.name}
            </span>
          </div>
          <div className="flex shrink-0 items-center gap-0.5">
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-6 w-6"
                  onClick={handleCopyFileViewUrl}
                  aria-label={t('Copy file view URL')}
                >
                  <Link2 className="h-3.5 w-3.5" />
                </Button>
              </TooltipTrigger>
              <TooltipContent side="bottom" className="text-xs">
                {t('Copy view URL')}
              </TooltipContent>
            </Tooltip>
            {!isPending ? (
              <>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                  className="h-6 w-6"
                  onClick={handleDownload}
                      aria-label={t('Download')}
                    >
                      <Download className="h-3.5 w-3.5" />
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent side="bottom" className="text-xs">
                    {t('Download')}
                  </TooltipContent>
                </Tooltip>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                  className="h-6 w-6"
                  onClick={handlePreview}
                      aria-label={t('Open preview')}
                    >
                      <ExternalLink className="h-3.5 w-3.5" />
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent side="bottom" className="text-xs">
                    {t('Open preview')}
                  </TooltipContent>
                </Tooltip>
              </>
            ) : null}
            {closePreviewButton}
          </div>
        </div>

        {showSecurityTab ? (
          <Tabs
            value={inspectorTab}
            onValueChange={(v) => {
              const next = v as InspectorTab
              if (!fileId) return
              navigate({
                to: '/projects/$projectId/storage/$bucketId',
                params: { projectId, bucketId },
                search: (prev: Record<string, unknown>) => {
                  const out: Record<string, unknown> = { ...prev, file: fileId }
                  if (next === 'overview') {
                    delete out.filePanel
                  } else {
                    out.filePanel = next
                  }
                  return out
                },
                replace: true,
              })
            }}
            className="flex min-h-0 flex-1 flex-col gap-0 overflow-hidden"
          >
            <div className="shrink-0 border-b border-border px-3 pb-3 pt-3 sm:px-6 sm:pb-4 sm:pt-4">
              <TabsList className="grid h-9 w-full grid-cols-3">
                <TabsTrigger value="overview" className="text-[12px] sm:text-[13px]">
                  {t('Overview')}
                </TabsTrigger>
                <TabsTrigger value="permissions" className="text-[12px] sm:text-[13px]">
                  {t('Permissions')}
                </TabsTrigger>
                <TabsTrigger value="tokens" className="text-[12px] sm:text-[13px]">
                  {t('Tokens')}
                </TabsTrigger>
              </TabsList>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
              <TabsContent
                value="overview"
                className="m-0 mt-0 block outline-none data-[state=inactive]:hidden"
              >
                {overviewBody}
              </TabsContent>
              <TabsContent
                value="permissions"
                className="m-0 mt-0 block outline-none data-[state=inactive]:hidden"
              >
                {permissionsTabBody}
              </TabsContent>
              <TabsContent
                value="tokens"
                className="m-0 mt-0 block outline-none data-[state=inactive]:hidden"
              >
                {tokensTabBody}
              </TabsContent>
            </div>
          </Tabs>
        ) : (
          <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
            {overviewBody}
          </div>
        )}
      </aside>
    </TooltipProvider>
    <TransformImageWizard
      open={transformWizardOpen}
      onClose={() => setTransformWizardOpen(false)}
      projectId={projectId}
      bucketId={bucketId}
      fileId={file.$id}
      fileName={file.name}
      preferAvif={avifSupported}
      originalSizeBytes={file.sizeOriginal}
      initialPreviewRequestWidthPx={inspectorPreviewRequestWidthPx}
    />
    </>
  )
}
