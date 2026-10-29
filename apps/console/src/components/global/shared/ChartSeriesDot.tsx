import { cn } from '@/lib/utils'

/** Small color swatch for chart legends and tooltip series rows. */
export function ChartSeriesDot({
  color,
  className,
}: {
  color: string
  className?: string
}) {
  return (
    <span
      className={cn('inline-block h-2 w-2 shrink-0 rounded-full', className)}
      style={{ backgroundColor: color }}
      aria-hidden
    />
  )
}
