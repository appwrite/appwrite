import type { LucideIcon } from 'lucide-react'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type ProductAvatarItem = {
  name: string
  icon: LucideIcon
  href?: string
}

type ProductAvatarsListProps = {
  items: readonly ProductAvatarItem[]
  className?: string
  ariaLabel?: string
}

const avatarClassName = cn(
  'flex size-9 items-center justify-center rounded-full border border-muted-foreground/6 bg-muted-foreground/[0.025] shadow-none backdrop-blur-sm transition-[border-color,opacity,transform] duration-300 dark:bg-muted/10',
  'ring-1 ring-background',
  'hover:z-10 hover:scale-105 hover:border-muted-foreground/10 hover:bg-muted-foreground/[0.04] sm:size-10',
)

const iconClassName = 'size-4 text-muted-foreground/70 sm:size-[17px]'

export function ProductAvatarsList({
  items,
  className,
  ariaLabel,
}: ProductAvatarsListProps) {
  const t = useT()
  return (
    <ul
      className={cn('inline-flex items-center justify-center ps-0', className)}
      aria-label={ariaLabel ? t(ariaLabel) : undefined}
    >
      {items.map((item, index) => {
        const Icon = item.icon

        return (
          <li
            key={item.name}
            className={cn('relative shrink-0', index > 0 && '-ms-2 sm:-ms-2.5')}
            style={{ zIndex: index + 1 }}
          >
            <Tooltip>
              <TooltipTrigger asChild>
                {item.href ? (
                  <MarketingSiteLink
                    href={item.href}
                    className={cn(avatarClassName, 'cursor-pointer')}
                    aria-label={t(item.name)}
                  >
                    <Icon className={iconClassName} strokeWidth={1.5} aria-hidden />
                  </MarketingSiteLink>
                ) : (
                  <div className={cn(avatarClassName, 'cursor-default')} aria-label={t(item.name)}>
                    <Icon className={iconClassName} strokeWidth={1.5} aria-hidden />
                  </div>
                )}
              </TooltipTrigger>
              <TooltipContent side="bottom" className="text-[12px]">
                {t(item.name)}
              </TooltipContent>
            </Tooltip>
          </li>
        )
      })}
    </ul>
  )
}
