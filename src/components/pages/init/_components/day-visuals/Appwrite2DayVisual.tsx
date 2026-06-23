import { cn } from '@/lib/utils'
import { productBentoContainer, productBentoIdle } from '@/components/pages/home/product-bento/MockSyntax'

export function Appwrite2DayVisual() {
  return (
    <div className="absolute inset-0 flex flex-col overflow-hidden">
      <div
        className={cn(
          'relative mx-auto flex h-full min-h-0 w-full max-w-[22rem] flex-col items-center justify-center overflow-hidden',
          productBentoContainer.shell,
        )}
      >
        <div
          className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_50%_42%,color-mix(in_srgb,var(--brand-cta)_16%,transparent),transparent_65%)] opacity-60 transition-opacity duration-500 group-hover:opacity-100 motion-reduce:group-hover:opacity-60"
          aria-hidden
        />
        <div
          className="pointer-events-none absolute inset-0 opacity-25 [background-image:radial-gradient(circle,color-mix(in_srgb,var(--border)_80%,transparent)_1px,transparent_1px)] [background-size:14px_14px] transition-opacity duration-500 group-hover:opacity-40 motion-reduce:group-hover:opacity-25"
          aria-hidden
        />

        <div className="relative z-10 flex flex-col items-center px-6 py-4 text-center">
          <div className="flex items-center justify-center gap-3.5 sm:gap-4">
            <span
              className={cn(
                'flex size-14 shrink-0 items-center justify-center rounded-xl border border-border bg-background/80 shadow-sm transition-[border-color,background-color,transform,box-shadow] duration-500 group-hover:scale-105 group-hover:border-[color-mix(in_srgb,var(--brand-cta)_35%,var(--border))] group-hover:bg-background group-hover:shadow-[0_0_28px_color-mix(in_srgb,var(--brand-cta)_20%,transparent)] motion-reduce:group-hover:scale-100',
              )}
            >
              <img
                src="/icons/appwrite.svg"
                alt=""
                className={cn(
                  'size-7 transition-[filter,opacity] duration-500',
                  'opacity-70 grayscale group-hover:opacity-100 group-hover:grayscale-0 motion-reduce:group-hover:opacity-70 motion-reduce:group-hover:grayscale',
                )}
                aria-hidden
              />
            </span>

            <div className="flex items-baseline">
              <span
                className={cn(
                  'font-aeonik-pro text-[clamp(3rem,10vw,4rem)] font-normal leading-none tracking-tight transition-colors duration-500',
                  productBentoIdle.text,
                )}
              >
                2.0
              </span>
              <span
                className={cn(
                  'font-aeonik-pro text-[clamp(3rem,10vw,4rem)] font-normal leading-none text-muted-foreground/40 transition-colors duration-500 group-hover:text-[var(--brand-cta)] motion-reduce:group-hover:text-muted-foreground/40',
                )}
                aria-hidden
              >
                _
              </span>
            </div>
          </div>

          <p className="mt-4 max-w-[12rem] text-[10px] leading-snug text-muted-foreground sm:text-[11px]">
            The next chapter of Appwrite
          </p>
        </div>
      </div>
    </div>
  )
}
