import type { LucideIcon } from 'lucide-react'
import { Fragment } from 'react'
import { Globe, Sparkles } from 'lucide-react'
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

function GroupDivider() {
  return (
    <span
      className="hidden h-px w-8 shrink-0 border-t border-dashed border-border sm:block"
      aria-hidden
    />
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
        'mt-10 flex flex-col items-center justify-center gap-8 sm:flex-row sm:flex-wrap sm:gap-10 lg:gap-12',
        className,
      )}
    >
      {groups.map((group, index) => (
        <Fragment key={group.label}>
          {index > 0 ? <GroupDivider /> : null}
          <ProductAvatarGroup label={group.label} products={group.products} />
        </Fragment>
      ))}
    </div>
  )
}
