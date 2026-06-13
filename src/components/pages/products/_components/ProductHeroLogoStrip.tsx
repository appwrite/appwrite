import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import type { ProductHeroLogoStripConfig } from '@/lib/products/hero-logo-strip'
import { cn } from '@/lib/utils'

type ProductHeroLogoStripProps = {
  config: ProductHeroLogoStripConfig
  className?: string
}

export function ProductHeroLogoStrip({ config, className }: ProductHeroLogoStripProps) {
  return (
    <div
      className={cn(
        'mt-10 border-t border-border/60 pt-8 sm:mt-12 sm:pt-10',
        className,
      )}
    >
      <p className="text-center text-[13px] font-normal leading-5 text-muted-foreground sm:text-[14px]">
        {config.title}
      </p>

      <ul className="mx-auto mt-6 flex max-w-5xl flex-nowrap items-center justify-center gap-x-0.5 px-1 sm:mt-8 sm:gap-x-1.5 md:gap-x-2.5 lg:gap-x-4">
        {config.items.map((item) => (
          <li key={item.key} className="shrink-0">
            <span
              className="group flex size-7 items-center justify-center transition-transform duration-200 hover:scale-110 motion-reduce:transition-none motion-reduce:hover:scale-100 sm:size-8 md:size-9"
              aria-label={item.name}
              title={item.name}
            >
              {config.variant === 'frameworks' ? (
                <FrameworkIcon
                  framework={item.key}
                  className="size-4 sm:size-5 md:size-6"
                />
              ) : (
                <RuntimeIcon
                  runtime={item.key}
                  className="size-4 sm:size-5 md:size-6"
                />
              )}
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}
