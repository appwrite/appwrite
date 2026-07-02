import type { ReactNode } from 'react'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type ProductFeatureVisualFrameProps = {
  eyebrow?: string
  title?: string
  headerIconSrc?: string
  tabs?: { id: string; label: string; active?: boolean }[]
  className?: string
  contentClassName?: string
  children: ReactNode
}

export function ProductFeatureVisualFrame({
  eyebrow,
  title,
  headerIconSrc,
  tabs,
  className,
  contentClassName,
  children,
}: ProductFeatureVisualFrameProps) {
  const t = useT()
  return (
    <div
      className={cn(
        'overflow-hidden rounded-xl border border-border bg-card/45',
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
                  ? 'bg-background text-foreground'
                  : 'text-muted-foreground',
              )}
            >
              {t(tab.label)}
            </span>
          ))}
        </div>
      ) : title || eyebrow ? (
        <div className="flex items-center gap-2.5 border-b border-border bg-muted/15 px-4 py-2.5">
          {headerIconSrc ? (
            <ProductFeaturePublicIcon src={headerIconSrc} className="size-5 shrink-0" />
          ) : null}
          <div className="min-w-0">
            {eyebrow ? (
              <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t(eyebrow)}
              </p>
            ) : null}
            {title ? (
              <p className={cn('text-[13px] font-semibold text-foreground', eyebrow && 'mt-0.5')}>
                {t(title)}
              </p>
            ) : null}
          </div>
        </div>
      ) : null}
      <div className={cn('p-4 sm:p-5', contentClassName)}>{children}</div>
    </div>
  )
}
