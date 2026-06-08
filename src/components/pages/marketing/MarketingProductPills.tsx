import type { LucideIcon } from 'lucide-react'
import { ArrowDown, ArrowRight, Globe, Sparkles } from 'lucide-react'
import { Fragment } from 'react'
import { ProductAvatarsList } from '@/components/global/shared/ProductAvatarsList'
import { pricingServices } from '@/lib/pricing/services'
import { cn } from '@/lib/utils'

export type MarketingProductPill = {
  label: string
  href: string
}

type MarketingProductPillsProps = {
  build: readonly MarketingProductPill[]
  deploy?: readonly MarketingProductPill[]
  protect?: readonly MarketingProductPill[]
  className?: string
}

const MARKETING_PRODUCT_ICONS: Record<string, LucideIcon> = {
  Advisor: Sparkles,
}

function getProductIcon(label: string): LucideIcon {
  return (
    pricingServices.find((service) => service.name === label)?.icon ??
    MARKETING_PRODUCT_ICONS[label] ??
    Globe
  )
}

function toAvatarItems(products: readonly MarketingProductPill[]) {
  return products.map((product) => ({
    name: product.label,
    icon: getProductIcon(product.label),
    href: product.href,
  }))
}

function ProductAvatarGroup({
  label,
  products,
}: {
  label: string
  products: readonly MarketingProductPill[]
}) {
  return (
    <div className="flex flex-col items-center gap-3">
      <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
        {label}
      </p>
      <ProductAvatarsList
        items={toAvatarItems(products)}
        ariaLabel={`${label} products`}
      />
    </div>
  )
}

function GroupArrow() {
  return (
    <div
      className="flex h-9 shrink-0 items-center justify-center sm:h-10"
      aria-hidden
    >
      <ArrowRight
        className="hidden size-4 text-muted-foreground/50 sm:block"
        strokeWidth={1.5}
      />
      <ArrowDown
        className="size-4 text-muted-foreground/50 sm:hidden"
        strokeWidth={1.5}
      />
    </div>
  )
}

export function MarketingProductPills({
  build,
  deploy,
  protect,
  className,
}: MarketingProductPillsProps) {
  const groups = [
    { label: 'Build', products: build },
    ...(deploy?.length ? [{ label: 'Deploy' as const, products: deploy }] : []),
    ...(protect?.length ? [{ label: 'Protect' as const, products: protect }] : []),
  ]

  return (
    <div
      className={cn(
        'mt-10 flex flex-col items-center justify-center gap-6 sm:flex-row sm:items-end sm:gap-4 lg:gap-6',
        className,
      )}
    >
      {groups.map((group, index) => (
        <Fragment key={group.label}>
          {index > 0 ? <GroupArrow /> : null}
          <ProductAvatarGroup label={group.label} products={group.products} />
        </Fragment>
      ))}
    </div>
  )
}
