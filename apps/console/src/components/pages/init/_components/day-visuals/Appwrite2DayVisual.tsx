import { AppwriteWordmark } from '@/components/global/shared/AppwriteWordmark'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { cn } from '@/lib/utils'

export function Appwrite2DayVisual() {
  return (
    <div className="absolute inset-0 flex items-center justify-center overflow-hidden">
      <div
        className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_50%_42%,color-mix(in_srgb,var(--brand-cta)_16%,transparent),transparent_65%)] opacity-60 transition-opacity duration-500 group-hover:opacity-100 motion-reduce:group-hover:opacity-60"
        aria-hidden
      />

      <div
        className={cn('relative z-10 inline-flex items-end gap-1.5', FORCE_LTR_CLASS)}
        dir="ltr"
        aria-hidden
      >
        <AppwriteWordmark className="h-7 w-auto sm:h-8" />
        <span className="pb-0.5 text-[10px] font-extralight tracking-tight text-muted-foreground sm:text-xs">
          / 2.0
        </span>
      </div>
    </div>
  )
}
