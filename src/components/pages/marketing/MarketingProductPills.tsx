import { ArrowDown, ArrowRight, ArrowUpRight, Globe } from 'lucide-react'
import { Fragment } from 'react'
import { ProductAvatarsList } from '@/components/global/shared/ProductAvatarsList'
import { useT } from '@/lib/i18n/translate'
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
  scale?: {
    href?: string
  }
  className?: string
}

function getProductIcon(label: string) {
  return (
    pricingServices.find((service) => service.name === label)?.icon ??
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
  const t = useT()
  return (
    <div className="flex flex-nowrap items-center gap-2.5 sm:gap-3">
      <p className="shrink-0 text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
        {t(label)}
      </p>
      <ProductAvatarsList
        items={toAvatarItems(products)}
        ariaLabel={`${t(label)} ${t('products')}`}
      />
    </div>
  )
}

function ScaleAvatarGroup({ href }: { href?: string }) {
  const t = useT()
  return (
    <div className="flex flex-nowrap items-center gap-2.5 sm:gap-3">
      <p className="shrink-0 text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
        {t('Scale')}
      </p>
      <ProductAvatarsList
        items={[{ name: t('Scale'), icon: ArrowUpRight, href }]}
        ariaLabel={t('Scale')}
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
  scale,
  className,
}: MarketingProductPillsProps) {
  const productGroups = [
    { label: 'Build', products: build },
    ...(deploy?.length ? [{ label: 'Deploy' as const, products: deploy }] : []),
    ...(protect?.length ? [{ label: 'Protect' as const, products: protect }] : []),
  ]

  return (
    <div
      className={cn(
        'mt-10 flex flex-col items-center justify-center gap-5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-center sm:gap-x-4 sm:gap-y-3 lg:gap-x-6',
        className,
      )}
    >
      {productGroups.map((group, index) => (
        <Fragment key={group.label}>
          {index > 0 ? <GroupArrow /> : null}
          <ProductAvatarGroup label={group.label} products={group.products} />
        </Fragment>
      ))}
      {scale ? (
        <>
          {productGroups.length > 0 ? <GroupArrow /> : null}
          <ScaleAvatarGroup href={scale.href} />
        </>
      ) : null}
    </div>
  )
}
