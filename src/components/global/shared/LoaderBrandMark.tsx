import { AppwriteWordmark } from '@/components/global/shared/AppwriteWordmark'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { cn } from '@/lib/utils'

export function LoaderBrandMark({ brand }: { brand: 'console' | 'partners' }) {
  if (brand === 'partners') {
    return (
      <div
        className={cn(
          'inline-flex items-center gap-2.5',
          FORCE_LTR_CLASS,
        )}
        dir="ltr"
      >
        <AppwriteWordmark className="h-8" />
        <span className="rounded-full border border-border px-2 py-0.5 text-[10px] font-light tracking-wide text-muted-foreground">
          Partners
        </span>
      </div>
    )
  }

  return (
    <div
      className={cn(
        'inline-flex items-end gap-1.5',
        FORCE_LTR_CLASS,
      )}
      dir="ltr"
    >
      <AppwriteWordmark className="h-8" />
      <span className="pb-0.5 text-xs font-extralight tracking-tight text-muted-foreground">
        / 2.0
      </span>
    </div>
  )
}
