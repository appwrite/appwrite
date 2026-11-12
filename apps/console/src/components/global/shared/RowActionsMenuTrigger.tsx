import { forwardRef } from 'react'
import { MoreHorizontal } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

/** Default row “⋯” trigger (tables, lists). */
export const rowActionsMenuTriggerClassName =
  'h-8 w-8 shrink-0 cursor-pointer p-0 text-muted-foreground hover:bg-accent hover:text-foreground'

/** Dense rows (variables tables, grid cards). */
export const rowActionsMenuTriggerCompactClassName =
  'h-7 w-7 shrink-0 cursor-pointer p-0 text-muted-foreground hover:bg-accent hover:text-foreground'

type RowActionsMenuTriggerProps = React.ComponentProps<typeof Button> & {
  /** Use 28×28px instead of 32×32px. */
  compact?: boolean
  /** Hidden until parent `.group` is hovered (grid cards, spreadsheet columns). */
  revealOnGroupHover?: boolean
}

export const RowActionsMenuTrigger = forwardRef<
  HTMLButtonElement,
  RowActionsMenuTriggerProps
>(function RowActionsMenuTrigger(
  {
    className,
    compact = false,
    revealOnGroupHover = false,
    variant = 'ghost',
    size = 'sm',
    ...props
  },
  ref,
) {
  const t = useT()
  return (
    <Button
      ref={ref}
      type="button"
      variant={variant}
      size={size}
      className={cn(
        compact
          ? rowActionsMenuTriggerCompactClassName
          : rowActionsMenuTriggerClassName,
        revealOnGroupHover &&
          'opacity-0 transition-opacity group-hover:opacity-100',
        className,
      )}
      {...props}
    >
      <MoreHorizontal className="h-4 w-4 shrink-0" />
      <span className="sr-only">{t('Actions')}</span>
    </Button>
  )
})
