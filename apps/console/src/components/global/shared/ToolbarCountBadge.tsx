import { cn } from '@/lib/utils'

export type ToolbarCountBadgePlacement = 'inline' | 'corner'

/** When `placement` is `inline`, controls pill contrast (default: soft gray). */
export type ToolbarCountBadgeInlineTone = 'muted' | 'emphasis'

export interface ToolbarCountBadgeProps {
  count: number
  placement: ToolbarCountBadgePlacement
  className?: string
  /** Only used with `placement="inline"`. `emphasis` uses brand-tinted fill for higher visibility. */
  inlineTone?: ToolbarCountBadgeInlineTone
}

/**
 * Small count pill for toolbar controls (e.g. Filters trigger vs tab labels).
 * `corner` - absolute overlay on a `relative` parent (primary). `inline` - pill in the row (see `inlineTone`).
 */
export function ToolbarCountBadge({
  count,
  placement,
  className,
  inlineTone = 'muted',
}: ToolbarCountBadgeProps) {
  if (count <= 0) return null

  const label = count > 99 ? '99+' : String(count)

  if (placement === 'inline') {
    return (
      <span
        className={cn(
          'flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full px-1 text-[10px] tabular-nums',
          inlineTone === 'emphasis'
            ? 'bg-primary/15 font-semibold text-primary'
            : 'bg-muted font-medium text-muted-foreground',
          className,
        )}
      >
        {label}
      </span>
    )
  }

  return (
    <span
      className={cn(
        'pointer-events-none absolute -end-1.5 -top-1.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full border-2 border-background bg-primary px-1 text-[10px] font-semibold tabular-nums text-primary-foreground',
        className,
      )}
    >
      {label}
    </span>
  )
}
