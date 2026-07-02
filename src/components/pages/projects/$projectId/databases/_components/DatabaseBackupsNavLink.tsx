import { Link, type LinkProps } from '@tanstack/react-router'
import { AlertTriangle, Archive } from 'lucide-react'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useBackupPolicies } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const NO_BACKUP_POLICIES_TOOLTIP =
  'No backup policies configured. Create a policy to automate backups.'

type DatabaseBackupsNavLinkProps = {
  projectId: string
  databaseId: string
  className?: string
  labelClassName?: string
} & Pick<LinkProps, 'to' | 'params'>

function NoBackupPoliciesWarningIcon() {
  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span
            className="inline-flex shrink-0 cursor-default"
            onClick={(e) => e.preventDefault()}
            onPointerDown={(e) => e.stopPropagation()}
          >
            <AlertTriangle
              className="h-3.5 w-3.5 text-amber-500 dark:text-amber-400"
              aria-hidden
            />
          </span>
        </TooltipTrigger>
        <TooltipContent side="right" className="max-w-xs">
          <p className="text-[13px]">{NO_BACKUP_POLICIES_TOOLTIP}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

export function DatabaseBackupsNavLink({
  projectId,
  databaseId,
  className,
  labelClassName,
  to,
  params,
}: DatabaseBackupsNavLinkProps) {
  const t = useT()
  const { data: policiesData, isLoading } = useBackupPolicies(
    projectId,
    databaseId,
  )
  const hasBackupPolicies = (policiesData?.policies?.length ?? 0) > 0
  const showWarning = !isLoading && !hasBackupPolicies

  return (
    <Link to={to} params={params} className={className}>
      <Archive className="h-3.5 w-3.5 shrink-0" />
      <span className={cn('min-w-0', labelClassName)}>{t('Backups')}</span>
      {showWarning && <NoBackupPoliciesWarningIcon />}
    </Link>
  )
}
