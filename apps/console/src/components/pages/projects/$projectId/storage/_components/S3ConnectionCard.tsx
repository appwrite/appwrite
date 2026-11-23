import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { useProjectConnectDialog } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import { useT } from '@/lib/i18n/translate'

export type S3ConnectionCardProps = {
  className?: string
}

/**
 * Sidebar action that opens the project Connect modal on the S3 tab.
 */
export function S3ConnectionCard({ className }: S3ConnectionCardProps) {
  const t = useT()
  const projectConnect = useProjectConnectDialog()

  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className={cn('h-8 w-full text-[12px]', className)}
      disabled={!projectConnect}
      onClick={() => projectConnect?.openConnect('s3')}
    >
      {t('Connect with S3')}
    </Button>
  )
}
