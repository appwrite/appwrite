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
  disabled?: boolean
  disabledTooltip?: string
  to?: string
  params?: object
}

export function NoBackupPoliciesWarningIcon({
  className,
}: {
  className?: string
} = {}) {
  const t = useT()
  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span
            className={cn('inline-flex shrink-0 cursor-default', className)}
            onClick={(e) => e.preventDefault()}
            onPointerDown={(e) => e.stopPropagation()}
          >
            <AlertTriangle
              className="h-3.5 w-3.5 text-amber-500 dark:text-amber-400"
              aria-label={t(NO_BACKUP_POLICIES_TOOLTIP)}
            />
          </span>
        </TooltipTrigger>
        <TooltipContent side="top" className="max-w-xs">
          <p className="text-[13px]">{t(NO_BACKUP_POLICIES_TOOLTIP)}</p>
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
  disabled = false,
  disabledTooltip,
  to,
  params,
}: DatabaseBackupsNavLinkProps) {
  const t = useT()
  const { data: policiesData, isLoading } = useBackupPolicies(
    projectId,
    databaseId,
    { enabled: !disabled },
  )
  const hasBackupPolicies = (policiesData?.policies?.length ?? 0) > 0
  const showWarning = !disabled && !isLoading && !hasBackupPolicies

  const content = (
    <>
      <Archive className="h-3.5 w-3.5 shrink-0" />
      <span className={cn('min-w-0', labelClassName)}>{t('Backups')}</span>
      {showWarning && <NoBackupPoliciesWarningIcon />}
    </>
  )

  if (disabled) {
    const item = (
      <span
        className={cn(className, 'cursor-not-allowed opacity-50')}
        aria-disabled="true"
      >
        {content}
      </span>
    )
    if (!disabledTooltip) return item
    return (
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>{item}</TooltipTrigger>
          <TooltipContent side="top" className="max-w-xs">
            <p className="text-[13px]">{t(disabledTooltip)}</p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    )
  }

  return (
    <Link
      to={to as LinkProps['to']}
      params={params as LinkProps['params']}
      className={className}
    >
      {content}
    </Link>
  )
}
