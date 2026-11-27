import type { ComponentProps, ReactNode } from 'react'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'

type DisabledReasonTooltipProps = {
  reason: string | null
  side?: ComponentProps<typeof TooltipContent>['side']
  children: ReactNode
}

export function DisabledReasonTooltip({
  reason,
  side,
  children,
}: DisabledReasonTooltipProps) {
  if (!reason) return children
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex shrink-0" tabIndex={0}>
          {children}
        </span>
      </TooltipTrigger>
      <TooltipContent side={side}>{reason}</TooltipContent>
    </Tooltip>
  )
}
