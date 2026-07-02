import { Server } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { useProjectConnectDialog } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import { useT } from '@/lib/i18n/translate'

export type S3ConnectionCardProps = {
  className?: string
}

/**
 * Sidebar teaser that opens the project Connect modal on the S3 tab (WIP).
 */
export function S3ConnectionCard({ className }: S3ConnectionCardProps) {
  const t = useT()
  const projectConnect = useProjectConnectDialog()

  return (
    <div
      className={cn(
        'w-full rounded-md border border-border bg-muted/30 px-2.5 py-2',
        className,
      )}
    >
      <div className="flex gap-2">
        <Server className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
        <div className="min-w-0 flex-1">
          <p className="text-[12px] font-medium leading-snug text-foreground">
            {t('S3-compatible access')}
          </p>
          <p className="mt-0.5 text-[11px] leading-snug text-muted-foreground">
            {t('Project endpoint and credentials for external tooling - in progress.')}
          </p>
        </div>
      </div>
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
  )
}
