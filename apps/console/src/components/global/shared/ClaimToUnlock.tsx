import type { ReactNode } from 'react'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'

/**
 * Explains a control the anonymous owner of a Zero project cannot use until
 * the project is claimed. Disable the wrapped control when `locked`.
 *
 * A disabled control takes no pointer or keyboard events, so the wrapper is
 * what the user hovers and focuses: it lets pointer events through the
 * control to itself, takes focus, and reads the reason to screen readers.
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
        <span
          role="button"
          tabIndex={0}
          aria-disabled="true"
          className={cn(
            className,
            'cursor-not-allowed rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&>*]:pointer-events-none',
          )}
        >
          {children}
          <span className="sr-only">{reason}</span>
        </span>
      </TooltipTrigger>
      <TooltipContent>
        <p>{reason}</p>
      </TooltipContent>
    </Tooltip>
  )
}
