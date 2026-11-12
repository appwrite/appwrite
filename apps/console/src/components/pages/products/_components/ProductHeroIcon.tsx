import type { ProductIcon } from '@/lib/products/types'
import { ProductIconTile } from '@/components/pages/products/_components/ProductTone'
import { cn } from '@/lib/utils'

type ProductHeroIconProps = {
  icon: ProductIcon
  name: string
  className?: string
}

export function ProductHeroIcon({ icon, name, className }: ProductHeroIconProps) {
  return (
    <div
      className={cn(
        'inline-flex items-center gap-2.5 rounded-full border border-border/80 bg-card/70 py-1 ps-1 pe-4 ring-1 ring-black/[0.03] backdrop-blur-sm dark:bg-card/40 dark:ring-white/[0.05]',
        className,
      )}
    >
      <ProductIconTile icon={icon} size="sm" className="rounded-full" />
      <span className="text-[13px] font-medium tracking-tight text-foreground">
        Appwrite {name}
      </span>
    </div>
  )
}
