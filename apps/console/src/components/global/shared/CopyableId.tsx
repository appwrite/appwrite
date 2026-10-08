import { useState } from 'react'
import { Copy, CheckCircle2 } from 'lucide-react'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type CopyableIdSize = 'xs' | 'sm' | 'md'
type CopyableIdVariant = 'badge' | 'inline'

interface CopyableIdProps {
  id: string
  /**
   * When set, shown instead of `id` while the clipboard still receives `id`
   * (e.g. shortened IPv6 in the UI, full address on copy).
   */
  displayText?: string
  className?: string
  /** Visual style. Default: 'badge' */
  variant?: CopyableIdVariant
  /** Size variant: 'xs' (10px), 'sm' (11px), 'md' (12px). Default: 'sm' */
  size?: CopyableIdSize
  /** Maximum width for truncation. Default: 140px */
  maxWidth?: number
  /** When true, size to the value and truncate if the parent is narrower. */
  constrainToContainer?: boolean
  /** When true, the copy icon is hidden until hover. Defaults to true for inline. */
  showCopyOnHover?: boolean
  /** Shows a toast on copy, e.g. "Endpoint" → "Endpoint copied". */
  copyToastLabel?: string
  /** CTA label shown instead of the value (full value available via title tooltip). */
  copyLabel?: string
}

const sizeStyles: Record<
  CopyableIdSize,
  { text: string; icon: string; padding: string }
> = {
  xs: {
    text: 'text-[10px]',
    icon: 'h-2.5 w-2.5',
    // Extra end padding so the copy icon isn't flush with the badge edge.
    padding: 'ps-1.5 pe-2 py-0.5',
  },
  sm: {
    text: 'text-[11px]',
    icon: 'h-3 w-3',
    padding: 'ps-1.5 pe-2 py-0.5',
  },
  md: {
    text: 'text-[12px]',
    icon: 'h-3 w-3',
    padding: 'ps-2.5 pe-3 py-1.5',
  },
}

export function CopyableId({
  id,
  displayText,
  className = '',
  variant = 'badge',
  size = 'sm',
  maxWidth = 140,
  constrainToContainer = false,
  showCopyOnHover,
  copyToastLabel,
  copyLabel,
}: CopyableIdProps) {
  const t = useT()
  const [copied, setCopied] = useState(false)
  const shown = copyLabel ? t(copyLabel) : (displayText ?? id)
  const copyOnHover = showCopyOnHover ?? variant === 'inline'
  const nativeTitle = copyLabel
    ? id
    : displayText && displayText !== id
      ? id
      : undefined

  const handleCopy = (e: React.MouseEvent) => {
    e.stopPropagation()
    e.preventDefault()
    navigator.clipboard.writeText(id)
    if (copyToastLabel) {
      toast.success(`${t(copyToastLabel)} ${t('copied')}`)
    }
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  const styles = sizeStyles[size]
  const shouldTruncate = !copyLabel && !/\s/.test(shown)

  return (
    <button
      type="button"
      onClick={handleCopy}
      title={nativeTitle}
      className={cn(
        'group/copyable inline-flex max-w-full items-center cursor-pointer transition-colors',
        copyLabel ? 'font-medium' : 'font-mono',
        variant === 'badge'
          ? 'gap-1.5 rounded bg-muted text-muted-foreground hover:bg-muted/80 hover:text-foreground'
          : 'gap-1.5 rounded-md border border-transparent bg-transparent text-foreground transition-[color,background-color,border-color] hover:border-border hover:bg-muted/40 hover:text-foreground',
        styles.text,
        styles.padding,
        constrainToContainer && 'min-w-0',
        className,
      )}
    >
      <span
        className={cn(
          'min-w-0',
          shouldTruncate
            ? 'truncate'
            : 'break-words whitespace-normal',
        )}
        style={
          shouldTruncate && !constrainToContainer
            ? { maxWidth: `${maxWidth}px` }
            : undefined
        }
      >
        {shown}
      </span>
      {copied ? (
        <CheckCircle2
          className={cn('shrink-0 text-emerald-500', styles.icon)}
        />
      ) : (
        <Copy
          className={cn(
            'shrink-0',
            styles.icon,
            copyOnHover &&
              'opacity-0 transition-opacity group-hover/copyable:opacity-100',
          )}
        />
      )}
    </button>
  )
}
