/**
 * Upload progress indicator component
 *
 * Shows file uploads with progress bars; completed uploads keep the same layout with a "View file" link.
 */

import {
  X,
  CheckCircle2,
  AlertCircle,
  Loader2,
  ExternalLink,
} from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { formatBytes } from '@/lib/utils/mock-data'
import type { UploadItem } from '@/lib/upload-queue/types'

interface UploadProgressProps {
  uploads: UploadItem[]
  onCancel?: (uploadId: string) => void
  /** Called when user dismisses a completed upload (close button) */
  onDismiss?: (uploadId: string) => void
  /** When true, do not use fixed positioning (for use inside a shared progress panel) */
  embedded?: boolean
  className?: string
}

export function UploadProgress({
  uploads,
  onCancel,
  onDismiss,
  embedded = false,
  className,
}: UploadProgressProps) {
  const t = useT()
  // Show pending, uploading, and completed; hide cancelled
  const displayUploads = uploads.filter((u) => u.status !== 'cancelled')

  if (displayUploads.length === 0) {
    return null
  }

  return (
    <div
      className={cn(
        'w-full max-w-sm space-y-2',
        !embedded && 'fixed z-50',
        !embedded && (className || 'bottom-4 end-4'),
        className && embedded && className,
      )}
    >
      {displayUploads.map((upload) => {
        const isCompleted = upload.status === 'completed'
        const isFailed = upload.status === 'failed'
        const isActive =
          upload.status === 'pending' || upload.status === 'uploading'
        const showCloseButton = isActive
          ? !!onCancel
          : isCompleted || isFailed
            ? !!onDismiss
            : false

        return (
          <div
            key={upload.id}
            className="rounded-lg border border-border bg-background p-3"
          >
            <div className="flex items-start gap-3">
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1">
                  {upload.status === 'uploading' ? (
                    <Loader2 className="h-4 w-4 animate-spin text-primary shrink-0" />
                  ) : upload.status === 'completed' ? (
                    <CheckCircle2 className="h-4 w-4 text-green-600 shrink-0" />
                  ) : upload.status === 'failed' ? (
                    <AlertCircle className="h-4 w-4 text-destructive shrink-0" />
                  ) : (
                    <Loader2 className="h-4 w-4 animate-spin text-muted-foreground shrink-0" />
                  )}
                  <p className="text-[13px] font-medium text-foreground truncate">
                    {upload.fileName}
                  </p>
                </div>
                <ProgressBarRow value={upload.progress} />
                <div className="flex items-center justify-between gap-2 flex-wrap">
                  <span className="text-[11px] text-muted-foreground">
                    {formatBytes(
                      Math.round((upload.progress / 100) * upload.fileSize),
                    )}{' '}
                    / {formatBytes(upload.fileSize)}
                  </span>
                  {isCompleted &&
                    upload.projectId &&
                    upload.bucketId &&
                    upload.fileId && (
                      <Link
                        to="/projects/$projectId/storage/$bucketId"
                        params={{
                          projectId: upload.projectId,
                          bucketId: upload.bucketId,
                        }}
                        search={{ file: upload.fileId }}
                        className="inline-flex items-center gap-1 text-[12px] link-neutral"
                      >
                        {t('View file')}
                        <ExternalLink className="h-3 w-3" />
                      </Link>
                    )}
                  {isFailed && upload.error && (
                    <span className="text-[11px] text-destructive truncate max-w-[200px]">
                      {upload.error}
                    </span>
                  )}
                </div>
              </div>
              {showCloseButton && (
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-6 w-6 p-0 shrink-0"
                  onClick={() =>
                    isActive ? onCancel?.(upload.id) : onDismiss?.(upload.id)
                  }
                  aria-label={isActive ? t('Cancel upload') : t('Dismiss')}
                >
                  <X className="h-3.5 w-3.5" />
                </Button>
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}
