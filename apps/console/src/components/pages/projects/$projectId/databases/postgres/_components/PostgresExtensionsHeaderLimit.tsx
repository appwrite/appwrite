import { useOrganizationPlan, usePostgresDatabaseExtensions } from '@/lib/react-query/hooks'
import { useProject } from '@/lib/react-query/hooks/projects'
import { cn } from '@/lib/utils'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'

type PostgresExtensionsHeaderLimitProps = {
  projectId: string
  databaseId: string
}

export function PostgresExtensionsHeaderLimit({
  projectId,
  databaseId,
}: PostgresExtensionsHeaderLimitProps) {
  const { project } = useProject(projectId)
  const { plan } = useOrganizationPlan(project?.teamId)
  const { installed, isLoading } = usePostgresDatabaseExtensions(
    projectId,
    databaseId,
  )

  const maxExtensions = plan?.dedicatedDatabases?.maxExtensions
  if (maxExtensions == null || maxExtensions <= 0) return null

  const installedCount = installed.length
  const usagePercent = (installedCount / maxExtensions) * 100

  const usageTone =
    usagePercent >= 90 ? 'critical' : usagePercent >= 75 ? 'warning' : 'normal'

  const tooltipText = isLoading
    ? `This plan allows up to ${maxExtensions} database extensions.`
    : `${installedCount} of ${maxExtensions} extensions are installed on this database.`

  const inlineLabel = `${installedCount.toLocaleString()} / ${maxExtensions.toLocaleString()}`

  return (
    <TooltipProvider delayDuration={200}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span
            className={cn(
              'shrink-0 text-[12px] font-normal tabular-nums',
              usageTone === 'critical' && 'text-destructive',
              usageTone === 'warning' && 'text-amber-600 dark:text-amber-500',
              usageTone === 'normal' && 'text-muted-foreground',
            )}
          >
            {inlineLabel}
          </span>
        </TooltipTrigger>
        <TooltipContent side="bottom" className="max-w-xs text-[12px]">
          {tooltipText}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
