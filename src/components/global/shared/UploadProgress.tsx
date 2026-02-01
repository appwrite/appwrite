/**
 * Upload progress indicator component
 *
 * Shows active file uploads with progress bars
 */

import { X, CheckCircle2, AlertCircle, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Progress } from '@/components/ui/progress'
import { cn } from '@/lib/utils'
import { formatBytes } from '@/lib/utils/mock-data'
import type { UploadItem } from '@/lib/upload-queue/types'

interface UploadProgressProps {
  uploads: UploadItem[]
  onCancel?: (uploadId: string) => void
  className?: string
}

export function UploadProgress({
  uploads,
  onCancel,
  className,
}: UploadProgressProps) {
  const activeUploads = uploads.filter(
    (u) => u.status === 'pending' || u.status === 'uploading',
  )

  if (activeUploads.length === 0) {
    return null
  }

  return (
    <div
      className={cn(
        'fixed z-50 w-full max-w-sm space-y-2',
        className || 'bottom-4 right-4',
      )}
    >
      {activeUploads.map((upload) => (
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
              <div className="flex items-center gap-2 mb-2">
                <Progress value={upload.progress} className="h-1.5 flex-1" />
                <span className="text-[11px] text-muted-foreground shrink-0">
                  {upload.progress}%
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-[11px] text-muted-foreground">
                  {formatBytes(
                    Math.round((upload.progress / 100) * upload.fileSize),
                  )}{' '}
                  / {formatBytes(upload.fileSize)}
                </span>
                {upload.status === 'failed' && upload.error && (
                  <span className="text-[11px] text-destructive truncate max-w-[200px]">
                    {upload.error}
                  </span>
                )}
              </div>
            </div>
            {onCancel &&
              (upload.status === 'pending' ||
                upload.status === 'uploading') && (
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-6 w-6 p-0 shrink-0"
                  onClick={() => onCancel(upload.id)}
                >
                  <X className="h-3.5 w-3.5" />
                </Button>
              )}
          </div>
        </div>
      ))}
    </div>
  )
}
