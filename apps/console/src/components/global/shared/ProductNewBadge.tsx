import { cn } from '@/lib/utils'

type ProductNewBadgeProps = {
  label: string
  className?: string
}

/** Temporary "New" chip for recently launched products. Fixed size across contexts. */
export function ProductNewBadge({ label, className }: ProductNewBadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex h-4 shrink-0 items-center rounded-full bg-[var(--brand-cta)]/10 px-1.5 text-[10px] font-medium leading-none text-[var(--brand-cta)]',
        className,
      )}
    >
      {label}
    </span>
  )
}
