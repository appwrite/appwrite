import { Braces, Layers, Table as TableIcon, type LucideIcon } from 'lucide-react'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import type { ProductHeroLogoStripConfig } from '@/lib/products/hero-logo-strip'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type EngineIcon = LucideIcon | typeof PostgresElephantIcon

const DATABASE_ENGINE_ICONS: Record<string, EngineIcon> = {
  tablesdb: TableIcon,
  documentsdb: Braces,
  vectorsdb: Layers,
  postgresql: PostgresElephantIcon,
  mysql: MySQLDolphinIcon,
}

type ProductHeroLogoStripProps = {
  config: ProductHeroLogoStripConfig
  className?: string
}

export function ProductHeroLogoStrip({ config, className }: ProductHeroLogoStripProps) {
  const t = useT()

  return (
    <div
      className={cn(
        'mt-10 border-t border-border/60 pt-9 sm:mt-12 sm:pt-11',
        className,
      )}
    >
      <p className="text-center text-[14px] font-normal leading-5 text-muted-foreground sm:text-[15px]">
        {t(config.title)}
      </p>

      {config.variant === 'engines' ? (
        <ul className="mx-auto mt-7 flex max-w-4xl flex-wrap items-center justify-center gap-x-5 gap-y-3 px-1 sm:mt-9 sm:gap-x-7 md:gap-x-8">
          {config.items.map((item) => {
            const Icon = DATABASE_ENGINE_ICONS[item.key]
            return (
              <li key={item.key} className="shrink-0">
                <span className="inline-flex items-center gap-2 text-foreground">
                  {Icon ? (
                    <Icon
                      className="size-4 shrink-0 text-muted-foreground sm:size-[18px]"
                      aria-hidden
                    />
                  ) : null}
                  <span className="text-[13px] font-medium tracking-tight sm:text-[14px]">
                    {item.name}
                  </span>
                </span>
              </li>
            )
          })}
        </ul>
      ) : (
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
      )}
    </div>
  )
}
