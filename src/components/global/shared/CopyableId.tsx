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
  className = '',
  size = 'sm',
  maxWidth = 140,
  tooltipSide = 'top',
}: CopyableIdProps) {
  const [copied, setCopied] = useState(false)

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
            onClick={handleCopy}
            className={cn(
              'inline-flex items-center gap-1.5 rounded bg-muted font-mono text-muted-foreground transition-colors hover:bg-muted/80 hover:text-foreground cursor-pointer',
              styles.text,
              styles.padding,
              className,
            )}
          >
            <span className="truncate" style={{ maxWidth: `${maxWidth}px` }}>
              {id}
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
          <p>{copied ? 'Copied!' : 'Click to copy'}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
