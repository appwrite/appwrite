import type { ReactNode } from 'react'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'

/**
 * Explains a control the anonymous owner of a Zero project cannot use until
 * the project is claimed. Disable the wrapped control when `locked`.
 */
export function ClaimToUnlock({
  locked,
  reason,
  children,
  className = 'block',
}: {
  locked: boolean
  reason: string
  children: ReactNode
  className?: string
}) {
  if (!locked) return <>{children}</>
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className={className}>{children}</span>
      </TooltipTrigger>
      <TooltipContent>
        <p>{reason}</p>
      </TooltipContent>
    </Tooltip>
  )
}
