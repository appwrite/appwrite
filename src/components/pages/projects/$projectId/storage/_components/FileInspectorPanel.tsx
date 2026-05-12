import { useMemo, useState, useEffect } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { ImageFormat } from '@appwrite.io/console'
import {
  AlertCircle,
  Download,
  ExternalLink,
  FileText,
  Link2,
  PanelRight,
  Trash2,
  Wand2,
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
} from '@/components/global/shared/StorageFilePreviewThumb'
import { formatBytes } from '@/lib/utils/mock-data'
import { STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS } from './files-documents-layout'
import { FileSecurity } from './FileSecurity'
import { TransformImageWizard } from './TransformImageWizard'
import { buildAdminFileViewUrl } from './transform-image-wizard-state'
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

export type FileInspectorPanelProps = {
  projectId: string
  bucketId: string
  /** Selected file from `?file=` or row selection */
  fileId: string | undefined
  /** URL `filePanel` — keeps deep links and context menu in sync with the inspector */
  panelTab?: 'overview' | 'security'
}

type InspectorTab = 'overview' | 'security'

/**
 * Right-hand inspector for Storage files workspace: overview, security (incl.
 * tokens), download/preview/delete — intended to replace the standalone file page.
 */
export function FileInspectorPanel({
  projectId,
  bucketId,
  fileId,
  panelTab,
}: FileInspectorPanelProps) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const avifSupported = useAvifSupport()
  const { data: file, isLoading } = useFile(projectId, bucketId, fileId)
  const { data: bucket } = useBucket(projectId, bucketId)
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSecurityTab = canShowBucketSecuritySettings(access, features)

  const [imageLoaded, setImageLoaded] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [transformWizardOpen, setTransformWizardOpen] = useState(false)

  const inspectorTab: InspectorTab = useMemo(() => {
    if (!showSecurityTab) return 'overview'
    return panelTab === 'security' ? 'security' : 'overview'
  }, [showSecurityTab, panelTab])

  useEffect(() => {
    setImageLoaded(false)
  }, [fileId])

  const previewUrl = useMemo(() => {
    if (!file || !fileId || !projectId || !bucketId) return null
    if (!isStoragePreviewSupportedMimeType(file.mimeType)) return null
    const raw = sdk.forProject(projectId).storage.getFilePreview({
      bucketId,
      fileId,
      width: 480,
      output: avifSupported ? ImageFormat.Avif : undefined,
    })
    return raw + (raw.includes('?') ? '&' : '?') + 'mode=admin'
  }, [file, fileId, projectId, bucketId, avifSupported])

  const deleteFileMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !bucketId || !fileId) {
        throw new Error('Project ID, Bucket ID, and File ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.deleteFile({ bucketId, fileId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: Dependencies.FILES })
      toast.success('File has been deleted')
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
    if (!projectId || !bucketId || !fileId) return
    const projectSdk = sdk.forProject(projectId)
    const url = projectSdk.storage.getFileDownload({ bucketId, fileId })
    const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
    window.open(urlWithMode, '_blank')
  }

  const handlePreview = () => {
    if (!projectId || !bucketId || !fileId) return
    const projectSdk = sdk.forProject(projectId)
    const url = projectSdk.storage.getFilePreview({ bucketId, fileId })
    const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
    window.open(urlWithMode, '_blank')
  }

  const handleCopyFileViewUrl = () => {
    if (!projectId || !bucketId || !fileId) return
    void copyToClipboard(
      'File view URL',
      buildAdminFileViewUrl(projectId, bucketId, fileId),
    )
  }

  if (!fileId) {
    return (
      <aside className="flex h-full min-h-0 w-full min-w-0 flex-col bg-muted/10">
        <div
          className={cn(
            'flex shrink-0 items-center gap-2 border-b border-border px-3',
            STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS,
          )}
        >
          <PanelRight className="h-4 w-4 text-muted-foreground" />
          <span className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            File
          </span>
        </div>
        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-5 py-8 text-center">
          <FileText className="h-10 w-10 text-muted-foreground/45" />
          <div className="space-y-1.5">
            <p className="text-[14px] font-medium text-foreground">
              No file selected
            </p>
            <p className="max-w-sm text-[13px] leading-relaxed text-muted-foreground">
              Select a row in the table to view preview and metadata, or use
              Create file in the header to upload.
            </p>
          </div>
        </div>
      </aside>
    )
  }

  if (isLoading || !file) {
    return (
      <TooltipProvider delayDuration={0}>
        <aside className="flex h-full min-h-0 w-full min-w-0 flex-col bg-muted/10">
          <div
            className={cn(
              'flex shrink-0 items-center justify-between gap-2 border-b border-border px-3',
              STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS,
            )}
          >
            <div className="flex items-center gap-2">
              <PanelRight className="h-4 w-4 text-muted-foreground" />
              <span className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                File
              </span>
            </div>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8"
                  onClick={handleCopyFileViewUrl}
                  aria-label="Copy file view URL"
                >
                  <Link2 className="h-3.5 w-3.5" />
                </Button>
              </TooltipTrigger>
              <TooltipContent side="bottom" className="text-xs">
                Copy view URL
              </TooltipContent>
            </Tooltip>
          </div>
          <div className="flex flex-1 items-center justify-center px-4">
            <p className="text-[12px] text-muted-foreground">
              {isLoading ? 'Loading file…' : 'File not found'}
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
      ? 'None'
      : file.compression === 'gzip'
        ? 'Gzip'
        : file.compression === 'zstd'
          ? 'Zstd'
          : file.compression.charAt(0).toUpperCase() + file.compression.slice(1)

  const PreviewPlaceholderIcon = getStorageFileIcon(file.mimeType)

  const overviewBody = (
    <div className="space-y-4 p-4">
      {isPending ? (
        <Badge variant="warning" className="text-[10px] shrink-0">
          Pending upload
        </Badge>
      ) : null}

      {!isPending && previewUrl ? (
        <div className="space-y-2">
          <div className="overflow-hidden rounded-lg border border-border bg-background">
            <div className="aspect-video w-full bg-muted/40">
              <img
                src={previewUrl}
                alt={file.name}
                onLoad={() => setImageLoaded(true)}
                className={cn(
                  'h-full w-full object-contain transition-opacity duration-300',
                  imageLoaded ? 'opacity-100' : 'opacity-0',
                )}
              />
            </div>
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 w-full gap-1.5 text-[13px]"
            onClick={() => setTransformWizardOpen(true)}
          >
            <Wand2 className="h-3.5 w-3.5 shrink-0" />
            Transform image
          </Button>
        </div>
      ) : !isPending ? (
        <div className="aspect-video w-full overflow-hidden rounded-lg border border-dashed border-border bg-muted/25">
          <div className="flex h-full min-h-[120px] flex-col items-center justify-center gap-2 px-4 text-center">
            <PreviewPlaceholderIcon className="h-11 w-11 shrink-0 text-muted-foreground/80" />
            <p className="max-w-[240px] text-[12px] leading-snug text-muted-foreground">
              {file.mimeType?.toLowerCase().startsWith('image/') &&
              !isStoragePreviewSupportedMimeType(file.mimeType)
                ? 'View this image in your browser with Open preview, or save a copy with Download.'
                : 'View this file in your browser with Open preview, or save a copy with Download.'}
            </p>
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
            MIME type
          </Label>
          <p className="mt-1 break-all font-mono text-[12px] text-foreground/90">
            {file.mimeType || '—'}
          </p>
        </div>
        <div>
          <Label className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
            Size
          </Label>
          <p className="mt-1 font-mono text-[12px] text-foreground/90">
            {formatBytes(file.sizeOriginal)}
          </p>
        </div>
        <div className="grid grid-cols-1 gap-3">
          <div>
            <Label className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              Created
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
              Updated
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
              Encryption
            </Label>
            <p className="mt-1 text-[12px] text-muted-foreground">
              {file.encryption === true ? 'Enabled' : 'Disabled'}
            </p>
          </div>
          <div>
            <Label className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              Compression
            </Label>
            <p className="mt-1 text-[12px] text-muted-foreground">
              {compressionLabel}
            </p>
          </div>
        </div>
        {file.signature ? (
          <div>
            <Label className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              MD5 signature
            </Label>
            <div className="mt-1">
              <CopyableId id={file.signature} size="sm" maxWidth={200} />
            </div>
          </div>
        ) : null}
        {isPending ? (
          <div>
            <Label className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              Upload progress
            </Label>
            <p className="mt-1 text-[12px] text-muted-foreground">
              {file.chunksUploaded} of {file.chunksTotal} chunks uploaded
            </p>
          </div>
        ) : null}
      </div>

      <div className="rounded-lg border border-red-500/30 bg-card/50 overflow-hidden">
        <div className="border-b border-red-500/20 px-4 py-3">
          <h3 className="text-[14px] font-semibold text-red-600 dark:text-red-400">
            Delete file
          </h3>
          <p className="mt-1 text-[12px] text-muted-foreground">
            Permanently delete this file. This action cannot be undone.
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
                <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                Delete
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md p-0">
              <DialogHeader className="px-6 pt-6 text-left">
                <DialogTitle>Delete file</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  Are you sure you want to delete{' '}
                  <strong>{file.name}</strong>? This action cannot be undone.
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
                  Cancel
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={deleteFileMutation.isPending}
                  onClick={() => deleteFileMutation.mutate()}
                >
                  Delete
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        </div>
      </div>
    </div>
  )

  const securityBody = (
    <div className="min-h-0 space-y-4 p-4">
      {bucket && !bucket.fileSecurity ? (
        <Alert variant="default" className="border-amber-500/30 bg-amber-500/5">
          <AlertCircle className="h-4 w-4 text-amber-500" />
          <AlertTitle className="text-[12px] font-medium text-amber-600 dark:text-amber-400">
            File level security is disabled
          </AlertTitle>
          <AlertDescription className="text-[11px] text-amber-600/80 dark:text-amber-400/80">
            File-level permissions only apply when file level security is enabled
            on the bucket.{' '}
            <span className="whitespace-nowrap">
              <Link
                to="/projects/$projectId/storage/$bucketId/security"
                params={{ projectId, bucketId }}
                className="font-medium underline hover:no-underline"
              >
                Enable in bucket Security
              </Link>
              .
            </span>
          </AlertDescription>
        </Alert>
      ) : null}
      <FileSecurity
        key={fileId}
        projectId={projectId}
        bucketId={bucketId}
        fileId={file.$id}
        variant="panel"
      />
    </div>
  )

  return (
    <>
      <TooltipProvider delayDuration={0}>
      <aside className="flex h-full min-h-0 w-full min-w-0 flex-col bg-muted/10">
        <div
          className={cn(
            'flex shrink-0 items-center justify-between gap-2 border-b border-border px-3',
            STORAGE_FILES_SPLIT_HEADER_ROW_H_CLASS,
          )}
        >
          <div className="flex min-w-0 flex-1 items-center gap-2">
            <PanelRight className="h-4 w-4 shrink-0 text-muted-foreground" />
            <span className="truncate text-left text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              File
            </span>
          </div>
          <div className="flex shrink-0 items-center gap-0.5">
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8"
                  onClick={handleCopyFileViewUrl}
                  aria-label="Copy file view URL"
                >
                  <Link2 className="h-3.5 w-3.5" />
                </Button>
              </TooltipTrigger>
              <TooltipContent side="bottom" className="text-xs">
                Copy view URL
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
                      className="h-8 w-8"
                      onClick={handleDownload}
                      aria-label="Download"
                    >
                      <Download className="h-3.5 w-3.5" />
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent side="bottom" className="text-xs">
                    Download
                  </TooltipContent>
                </Tooltip>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="h-8 w-8"
                      onClick={handlePreview}
                      aria-label="Open preview"
                    >
                      <ExternalLink className="h-3.5 w-3.5" />
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent side="bottom" className="text-xs">
                    Open preview
                  </TooltipContent>
                </Tooltip>
              </>
            ) : null}
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
                  if (next === 'security') {
                    out.filePanel = 'security'
                  } else {
                    delete out.filePanel
                  }
                  return out
                },
                replace: true,
              })
            }}
            className="flex min-h-0 flex-1 flex-col gap-0 overflow-hidden"
          >
            <div className="shrink-0 border-b border-border px-3 pb-3 pt-3 sm:px-6 sm:pb-4 sm:pt-4">
              <TabsList className="grid h-9 w-full grid-cols-2">
                <TabsTrigger value="overview" className="text-[13px]">
                  Overview
                </TabsTrigger>
                <TabsTrigger value="security" className="text-[13px]">
                  Security
                </TabsTrigger>
              </TabsList>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto">
              <TabsContent
                value="overview"
                className="m-0 mt-0 h-full outline-none data-[state=inactive]:hidden"
              >
                {overviewBody}
              </TabsContent>
              <TabsContent
                value="security"
                className="m-0 mt-0 h-full outline-none data-[state=inactive]:hidden"
              >
                {securityBody}
              </TabsContent>
            </div>
          </Tabs>
        ) : (
          <div className="min-h-0 flex-1 overflow-y-auto">{overviewBody}</div>
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
    />
    </>
  )
}
