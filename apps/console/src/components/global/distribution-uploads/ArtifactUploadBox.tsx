import { useEffect } from 'react'
import { Link } from '@tanstack/react-router'
import {
  useQueryClient,
  type QueryClient,
  type QueryKey,
} from '@tanstack/react-query'
import {
  AlertCircle,
  CheckCircle2,
  ExternalLink,
  Loader2,
  RotateCcw,
  X,
} from 'lucide-react'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import { Button } from '@/components/ui/button'
import {
  cancelArtifactUpload,
  dismissArtifactUpload,
  retryArtifactUpload,
  takeArtifactUploadRefreshes,
  useArtifactUploads,
  type ArtifactUpload,
} from '@/lib/distribution/uploads'
import { useT } from '@/lib/i18n/translate'
import {
  formatBinaryBytes,
  formatBinaryBytesProgress,
} from '@/lib/utils/byte-display-unit'

function refresh(queryClient: QueryClient, queryKey: QueryKey): void {
  queryClient.removeQueries({ queryKey, type: 'inactive' })
  void queryClient.invalidateQueries({ queryKey })
}

function UploadIcon({ status }: { status: ArtifactUpload['status'] }) {
  if (status === 'completed') {
    return <CheckCircle2 className="h-4 w-4 shrink-0 text-green-600" />
  }
  if (status === 'failed') {
    return <AlertCircle className="h-4 w-4 shrink-0 text-destructive" />
  }
  return <Loader2 className="h-4 w-4 shrink-0 animate-spin text-primary" />
}

function UploadProgressBar({ upload }: { upload: ArtifactUpload }) {
  if (upload.status !== 'uploading' || upload.chunked) {
    return <ProgressBarRow value={upload.progress} />
  }
  return (
    <div
      role="progressbar"
      aria-busy="true"
      aria-label={upload.fileName}
      className="mb-2 h-1.5 w-full overflow-hidden rounded-full bg-primary/20"
    >
      <div className="h-full w-full animate-pulse rounded-full bg-primary/70" />
    </div>
  )
}

function UploadStatus({ upload }: { upload: ArtifactUpload }) {
  const t = useT()
  if (upload.status === 'failed') {
    return (
      <span className="min-w-0 truncate text-[11px] text-destructive">
        {upload.error || t('Upload failed')}
      </span>
    )
  }
  if (upload.status === 'completed') {
    return (
      <span className="text-[11px] text-muted-foreground">
        {t('Uploaded. Appwrite is inspecting the binary.')}
      </span>
    )
  }
  if (!upload.chunked) {
    return (
      <span className="text-[11px] tabular-nums text-muted-foreground">
        {t('Uploading…')} {formatBinaryBytes(upload.size)}
      </span>
    )
  }
  const uploaded = Math.round((upload.progress / 100) * upload.size)
  return (
    <span className="text-[11px] tabular-nums text-muted-foreground">
      {formatBinaryBytesProgress(uploaded, upload.size)}
    </span>
  )
}

function UploadCard({ upload }: { upload: ArtifactUpload }) {
  const t = useT()
  const active = upload.status === 'uploading'

  return (
    <div
      data-testid={`artifact-upload-${upload.id}`}
      data-status={upload.status}
      className="rounded-lg border border-border bg-background p-3"
    >
      <div className="flex items-start gap-3">
        <div className="min-w-0 flex-1">
          <div className="mb-1 flex items-center gap-2">
            <UploadIcon status={upload.status} />
            <p
              className="truncate text-[13px] font-medium text-foreground"
              dir="auto"
              title={upload.fileName}
            >
              {upload.fileName}
            </p>
          </div>
          <UploadProgressBar upload={upload} />
          <div className="flex flex-wrap items-center justify-between gap-2">
            <UploadStatus upload={upload} />
            {upload.status === 'completed' ? (
              <Link
                to="/projects/$projectId/distribution/$appId/artifacts"
                params={{ projectId: upload.projectId, appId: upload.appId }}
                className="inline-flex items-center gap-1 text-[12px] link-neutral"
              >
                {t('View artifacts')}
                <ExternalLink className="h-3 w-3" />
              </Link>
            ) : upload.status === 'failed' ? (
              <button
                type="button"
                className="inline-flex items-center gap-1 text-[12px] link-neutral"
                onClick={() => retryArtifactUpload(upload.id)}
              >
                <RotateCcw className="h-3 w-3" />
                {t('Retry')}
              </button>
            ) : null}
          </div>
        </div>
        <Button
          variant="ghost"
          size="sm"
          className="h-6 w-6 shrink-0 p-0"
          onClick={() =>
            active
              ? cancelArtifactUpload(upload.id)
              : dismissArtifactUpload(upload.id)
          }
          aria-label={active ? t('Cancel upload') : t('Dismiss')}
        >
          <X className="h-3.5 w-3.5" />
        </Button>
      </div>
    </div>
  )
}

export function ArtifactUploadBox({ projectId }: { projectId: string }) {
  const queryClient = useQueryClient()
  const uploads = useArtifactUploads(projectId)

  useEffect(() => {
    for (const { appId, artifactId, completed } of takeArtifactUploadRefreshes(
      projectId,
    )) {
      refresh(queryClient, ['distribution-artifacts', projectId, appId])
      if (completed) {
        refresh(queryClient, [
          'distribution-artifact',
          projectId,
          appId,
          artifactId,
        ])
      }
    }
  }, [uploads, projectId, queryClient])

  if (uploads.length === 0) return null

  return (
    <div className="w-full max-w-sm space-y-2">
      {uploads.map((upload) => (
        <UploadCard key={upload.id} upload={upload} />
      ))}
    </div>
  )
}
