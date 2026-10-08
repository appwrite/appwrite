import { AlertTriangle } from 'lucide-react'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const TOOLTIP_DEFAULT =
  'Payment failed - update billing to avoid interrupting your projects and services.'

const TOOLTIP_READONLY =
  'Payment failed - this organization has restricted access until the outstanding invoice is paid. Project and service changes are limited; open Billing to update payment and restore access.'

type FailedInvoiceWarningIconProps = {
  show: boolean
  /** Organization is in billing read-only; use escalated tooltip copy */
  orgBillingReadonly?: boolean
  /** e.g. absolute placement in compact triggers */
  className?: string
  iconClassName?: string
  /** Native title only - use inside tight buttons where a tooltip would fight clicks */
  suppressTooltip?: boolean
}

export function FailedInvoiceWarningIcon({
  show,
  orgBillingReadonly,
  className,
  iconClassName,
  suppressTooltip,
}: FailedInvoiceWarningIconProps) {
  const t = useT()
  if (!show) return null

  const tooltip = t(orgBillingReadonly ? TOOLTIP_READONLY : TOOLTIP_DEFAULT)

  if (suppressTooltip) {
    return (
      <span
        className={cn('inline-flex shrink-0', className)}
        title={tooltip}
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
          <p className="text-[13px]">{tooltip}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
