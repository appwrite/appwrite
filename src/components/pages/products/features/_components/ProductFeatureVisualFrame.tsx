import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

type ProductFeatureVisualFrameProps = {
  eyebrow?: string
  title?: string
  tabs?: { id: string; label: string; active?: boolean }[]
  className?: string
  contentClassName?: string
  children: ReactNode
}

export function ProductFeatureVisualFrame({
  eyebrow,
  title,
  tabs,
  className,
  contentClassName,
  children,
}: ProductFeatureVisualFrameProps) {
  return (
    <div
      className={cn(
        'overflow-hidden rounded-xl border border-border bg-card/45 shadow-sm',
        className,
      )}
    >
      {tabs?.length ? (
        <div className="flex items-center gap-1 overflow-x-auto border-b border-border bg-muted/15 px-2 py-1.5 sm:px-3">
          {tabs.map((tab) => (
            <span
              key={tab.id}
              className={cn(
                'shrink-0 rounded-md px-2.5 py-1 text-[11px] font-medium transition-colors sm:text-[12px]',
                tab.active
                  ? 'bg-background text-foreground shadow-sm'
                  : 'text-muted-foreground',
              )}
            >
              {tab.label}
            </span>
          ))}
        </div>
      ) : title || eyebrow ? (
        <div className="border-b border-border bg-muted/15 px-4 py-2.5">
          {eyebrow ? (
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {eyebrow}
            </p>
          ) : null}
          {title ? (
            <p className={cn('text-[13px] font-semibold text-foreground', eyebrow && 'mt-0.5')}>
              {title}
            </p>
          ) : null}
        </div>
      ) : null}
      <div className={cn('p-4 sm:p-5', contentClassName)}>{children}</div>
    </div>
  )
}
