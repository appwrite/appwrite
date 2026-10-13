import type { ProductIcon } from '@/lib/products/types'
import { ProductIconTile } from '@/components/pages/products/_components/ProductTone'
import { cn } from '@/lib/utils'

type ProductHeroIconProps = {
  icon: ProductIcon
  name: string
  className?: string
  size?: 'default' | 'og'
}

export function ProductHeroIcon({
  icon,
  name,
  className,
  size = 'default',
}: ProductHeroIconProps) {
  const og = size === 'og'

  return (
    <div
      className={cn(
        'inline-flex items-center rounded-full border border-border/80 bg-card/70 ring-1 ring-black/[0.03] backdrop-blur-sm dark:bg-card/40 dark:ring-white/[0.05]',
        og ? 'gap-3.5 py-1.5 ps-1.5 pe-6' : 'gap-2.5 py-1 ps-1 pe-4',
        className,
      )}
    >
      <ProductIconTile icon={icon} size={og ? 'md' : 'sm'} className="rounded-full" />
      <span
        className={cn(
          'font-medium tracking-tight text-foreground',
          og ? 'text-[22px]' : 'text-[13px]',
        )}
      >
        Appwrite {name}
      </span>
    </div>
  )
}
