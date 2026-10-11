import { AlertTriangle } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
const BLOCKED_TOOLTIP =
  'This project is temporarily unavailable. Access has been restricted.'

type ProjectBlockedBadgeProps = {
  show: boolean
  className?: string
  /** Compact badge only (e.g. project selector rows). */
  compact?: boolean
}

export function ProjectBlockedBadge({
  show,
  className,
  compact = false,
}: ProjectBlockedBadgeProps) {
  const t = useT()
  if (!show) return null

  const tooltip = t(BLOCKED_TOOLTIP)

  if (compact) {
    return (
      <Badge
        variant="error"
        className={cn('gap-1 text-[10px] font-medium shrink-0', className)}
      >
        <AlertTriangle className="h-3 w-3" aria-hidden />
        {t('Blocked')}
      </Badge>
    )
  }

  return (
    <div className={cn('flex shrink-0 items-center gap-1.5', className)}>
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>
            <span className="inline-flex shrink-0 cursor-default">
              <AlertTriangle
                className="h-3.5 w-3.5 text-red-600 dark:text-red-400"
                aria-hidden
              />
            </span>
          </TooltipTrigger>
          <TooltipContent side="top" className="max-w-xs">
            <p className="text-[13px]">{tooltip}</p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
      <Badge variant="error" className="gap-1 text-[10px] font-medium shrink-0">
        <AlertTriangle className="h-3 w-3" aria-hidden />
        {t('Blocked')}
      </Badge>
    </div>
  )
}
