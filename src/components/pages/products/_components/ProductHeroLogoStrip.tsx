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
        'mt-10 border-t border-border/60 pt-9 sm:mt-12 sm:pt-11',
        className,
      )}
    >
      <p className="text-center text-[14px] font-normal leading-5 text-muted-foreground sm:text-[15px]">
        {config.title}
      </p>

      <ul className="mx-auto mt-7 flex max-w-6xl flex-nowrap items-center justify-center gap-x-1 px-1 sm:mt-9 sm:gap-x-2 md:gap-x-3.5 lg:max-w-7xl lg:gap-x-5">
        {config.items.map((item) => (
          <li key={item.key} className="shrink-0">
            <span
              className="group flex size-9 items-center justify-center transition-transform duration-200 hover:scale-110 motion-reduce:transition-none motion-reduce:hover:scale-100 sm:size-10 md:size-11 lg:size-12"
              aria-label={item.name}
              title={item.name}
            >
              {config.variant === 'frameworks' ? (
                <FrameworkIcon
                  framework={item.key}
                  className="size-5 sm:size-6 md:size-7 lg:size-8"
                />
              ) : (
                <RuntimeIcon
                  runtime={item.key}
                  className="size-5 sm:size-6 md:size-7 lg:size-8"
                />
              )}
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}
