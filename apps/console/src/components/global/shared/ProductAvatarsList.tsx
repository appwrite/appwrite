import type { LucideIcon } from 'lucide-react'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { SpriteIcon } from '@/components/ui/sprite-icon'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type ProductAvatarItem = {
  name: string
  icon?: LucideIcon
  imageSrc?: string
  label?: string
  href?: string
}

type ProductAvatarsListProps = {
  items: readonly ProductAvatarItem[]
  className?: string
  ariaLabel?: string
}

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
        const avatarContent = item.label ? (
          <span className="text-[11px] font-medium text-muted-foreground/80 sm:text-[12px]">
            {item.label}
          </span>
        ) : item.imageSrc ? (
          <img
            src={item.imageSrc}
            alt=""
            className={cn(
              'size-4 object-contain sm:size-[17px]',
              PUBLIC_ICON_MUTED_CLASSES,
            )}
          />
        ) : Icon ? (
          <SpriteIcon icon={Icon} className={iconClassName} strokeWidth={1.5} />
        ) : null

        return (
          <li
            key={item.name}
            className={cn('relative shrink-0', index > 0 && '-ms-2 sm:-ms-2.5')}
            style={{ zIndex: items.length - index }}
          >
            <Tooltip>
              <TooltipTrigger asChild>
                {item.href ? (
                  <MarketingSiteLink
                    href={item.href}
                    className={'product-avatar cursor-pointer'}
                    aria-label={t(item.name)}
                  >
                    {avatarContent}
                  </MarketingSiteLink>
                ) : (
                  <div
                    className={'product-avatar cursor-default'}
                    aria-label={t(item.name)}
                  >
                    {avatarContent}
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
