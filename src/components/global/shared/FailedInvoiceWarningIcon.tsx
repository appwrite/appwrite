import { AlertTriangle } from 'lucide-react'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'

const TOOLTIP =
  'Payment failed - update billing to avoid interrupting your projects and services.'

type FailedInvoiceWarningIconProps = {
  show: boolean
  /** e.g. absolute placement in compact triggers */
  className?: string
  iconClassName?: string
  /** Native title only - use inside tight buttons where a tooltip would fight clicks */
  suppressTooltip?: boolean
}

export function FailedInvoiceWarningIcon({
  show,
  className,
  iconClassName,
  suppressTooltip,
}: FailedInvoiceWarningIconProps) {
  if (!show) return null

  if (suppressTooltip) {
    return (
      <span
        className={cn('inline-flex shrink-0', className)}
        title={TOOLTIP}
      >
        <AlertTriangle
          className={cn(
            'h-3.5 w-3.5 text-red-600 dark:text-red-400',
            iconClassName,
          )}
          aria-hidden
        />
      </span>
    )
  }

  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className={cn('inline-flex shrink-0 cursor-default', className)}>
            <AlertTriangle
              className={cn(
                'h-3.5 w-3.5 text-red-600 dark:text-red-400',
                iconClassName,
              )}
              aria-hidden
            />
          </span>
        </TooltipTrigger>
        <TooltipContent side="top" className="max-w-xs">
          <p className="text-[13px]">{TOOLTIP}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
