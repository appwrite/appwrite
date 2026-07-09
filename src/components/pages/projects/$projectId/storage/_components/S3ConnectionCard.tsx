import { useState } from 'react'
import { Check, Copy, Server } from 'lucide-react'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { useProjectConnectDialog } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import { getProjectS3StorageEndpoint } from '@/lib/storage-s3'
import { useT } from '@/lib/i18n/translate'

export type S3ConnectionCardProps = {
  projectId: string
  className?: string
}

/**
 * Sidebar teaser that opens the project Connect modal on the S3 tab.
 */
export function S3ConnectionCard({ projectId, className }: S3ConnectionCardProps) {
  const t = useT()
  const [copied, setCopied] = useState(false)
  const projectConnect = useProjectConnectDialog()
  const s3Endpoint = projectId ? getProjectS3StorageEndpoint(projectId) : ''

  const handleCopyEndpoint = async () => {
    if (!s3Endpoint) return
    try {
      await navigator.clipboard.writeText(s3Endpoint)
      setCopied(true)
      toast.success(`${t('Endpoint')} ${t('copied')}`)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      toast.error(t('Failed to copy'))
    }
  }

  return (
    <div className={cn('w-full', className)}>
      <div className="pb-2.5">
        <div className="flex items-start gap-2">
          <span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-muted">
            <Server className="size-3 text-muted-foreground" aria-hidden />
          </span>
          <div className="min-w-0">
            <p className="text-[12px] font-semibold text-foreground">
              {t('S3-compatible access')}
            </p>
            <p className="mt-0.5 text-[11px] leading-snug text-muted-foreground">
              {t(
                'Project endpoint and credentials for rclone, Terraform, AWS CLI, and custom pipelines.',
              )}
            </p>
          </div>
        </div>
      </div>

      <div className="-mx-2 -mb-2 border-t border-border bg-muted/25 px-2 py-2">
        {s3Endpoint ? (
          <div className="flex items-center gap-1.5">
            <p
              className="min-w-0 flex-1 truncate rounded bg-background/80 px-2 py-1 font-mono text-[10px] text-foreground"
              title={s3Endpoint}
            >
              {s3Endpoint}
            </p>
            <button
              type="button"
              onClick={handleCopyEndpoint}
              className="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded border border-border bg-background/80 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
              aria-label={t('Copy')}
            >
              {copied ? (
                <Check className="size-3 text-emerald-500" />
              ) : (
                <Copy className="size-3" />
              )}
            </button>
          </div>
        ) : null}

        <Button
          type="button"
          variant="outline"
          size="sm"
          className="mt-2 h-8 w-full text-[12px]"
          disabled={!projectConnect}
          onClick={() => projectConnect?.openConnect('s3')}
        >
          {t('Open in Connect')}
        </Button>
      </div>
    </div>
  )
}
