import { useState } from 'react'
import { Copy, CheckCircle2 } from 'lucide-react'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
  TooltipProvider,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'

type CopyableIdSize = 'xs' | 'sm' | 'md'

interface CopyableIdProps {
  id: string
  /**
   * When set, shown instead of `id` while the clipboard still receives `id`
   * (e.g. shortened IPv6 in the UI, full address on copy).
   */
  displayText?: string
  className?: string
  /** Size variant: 'xs' (10px), 'sm' (11px), 'md' (12px). Default: 'sm' */
  size?: CopyableIdSize
  /** Maximum width for truncation. Default: 140px */
  maxWidth?: number
  /** Tooltip position. Default: 'top' */
  tooltipSide?: 'top' | 'bottom' | 'left' | 'right'
}

const sizeStyles: Record<
  CopyableIdSize,
  { text: string; icon: string; padding: string }
> = {
  xs: {
    text: 'text-[10px]',
    icon: 'h-2.5 w-2.5',
    padding: 'px-1.5 py-0.5',
  },
  sm: {
    text: 'text-[11px]',
    icon: 'h-3 w-3',
    padding: 'px-1.5 py-0.5',
  },
  md: {
    text: 'text-[12px]',
    icon: 'h-3.5 w-3.5',
    padding: 'px-2 py-1',
  },
}

export function CopyableId({
  id,
  displayText,
  className = '',
  size = 'sm',
  maxWidth = 140,
  tooltipSide = 'top',
}: CopyableIdProps) {
  const [copied, setCopied] = useState(false)
  const shown = displayText ?? id
  const showFullInTooltip = Boolean(displayText && displayText !== id)

  const handleCopy = (e: React.MouseEvent) => {
    e.stopPropagation()
    e.preventDefault()
    navigator.clipboard.writeText(id)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  const styles = sizeStyles[size]

  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            onClick={handleCopy}
            title={showFullInTooltip ? id : undefined}
            className={cn(
              'inline-flex items-center gap-1.5 rounded bg-muted font-mono text-muted-foreground transition-colors hover:bg-muted/80 hover:text-foreground cursor-pointer',
              styles.text,
              styles.padding,
              className,
            )}
          >
            <span className="truncate" style={{ maxWidth: `${maxWidth}px` }}>
              {shown}
            </span>
            {copied ? (
              <CheckCircle2
                className={cn('shrink-0 text-emerald-500', styles.icon)}
              />
            ) : (
              <Copy className={cn('shrink-0', styles.icon)} />
            )}
          </button>
        </TooltipTrigger>
        <TooltipContent side={tooltipSide}>
          {copied ? (
            <p>Copied!</p>
          ) : showFullInTooltip ? (
            <div className="max-w-xs space-y-1">
              <p>Click to copy</p>
              <p className="break-all font-mono text-[11px] text-muted-foreground">
                {id}
              </p>
            </div>
          ) : (
            <p>Click to copy</p>
          )}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
