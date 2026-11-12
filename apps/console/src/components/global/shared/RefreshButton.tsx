import { useCallback, useEffect, useRef, useState } from 'react'
import { RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

/**
 * Spins while `isActive` is true, and always finishes the current full
 * rotation before removing `animate-spin` (no mid-turn snap).
 */
function useRefreshSpin(isActive: boolean) {
  const [isSpinning, setIsSpinning] = useState(false)
  const isActiveRef = useRef(isActive)
  isActiveRef.current = isActive

  useEffect(() => {
    if (isActive) {
      setIsSpinning(true)
    }
  }, [isActive])

  const startSpin = useCallback(() => {
    setIsSpinning(true)
  }, [])

  const onAnimationIteration = useCallback(() => {
    // Stop only at a full-circle boundary, and only once the refresh is done.
    if (!isActiveRef.current) {
      setIsSpinning(false)
    }
  }, [])

  return { isSpinning, onAnimationIteration, startSpin }
}

type RefreshButtonProps = {
  onClick?: () => void
  isRefreshing?: boolean
  disabled?: boolean
  /** Tooltip and aria-label. Defaults to "Refresh". */
  tooltip?: string
  className?: string
  iconClassName?: string
  variant?: 'outline' | 'ghost'
  type?: 'button' | 'submit' | 'reset'
}

export function RefreshButton({
  onClick,
  isRefreshing = false,
  disabled = false,
  tooltip,
  className,
  iconClassName,
  variant = 'outline',
  type = 'button',
}: RefreshButtonProps) {
  const t = useT()
  const label = tooltip ?? t('Refresh')
  const { isSpinning, onAnimationIteration, startSpin } =
    useRefreshSpin(isRefreshing)

  const handleClick = () => {
    startSpin()
    onClick?.()
  }

  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            type={type}
            variant={variant}
            size="sm"
            onClick={handleClick}
            disabled={disabled || isRefreshing}
            aria-label={label}
            className={cn(
              'h-9 w-9 shrink-0 p-0 border-border bg-transparent text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-50',
              className,
            )}
          >
            <RefreshCw
              className={cn('h-4 w-4', isSpinning && 'animate-spin', iconClassName)}
              onAnimationIteration={onAnimationIteration}
            />
          </Button>
        </TooltipTrigger>
        <TooltipContent side="bottom">
          <p>{label}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
