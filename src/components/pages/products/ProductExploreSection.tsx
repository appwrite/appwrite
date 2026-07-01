'use client'

import { ArrowRight } from 'lucide-react'
import { MarketingSectionHeading } from '@/components/pages/marketing/MarketingSections'
import { scrollMarketingMainToTop } from '@/lib/marketing/MarketingScrollToTop'
import { PRODUCT_IDS, PRODUCT_REGISTRY } from '@/lib/products/registry'
import type { ProductId } from '@/lib/products/types'
import { cn } from '@/lib/utils'

type ProductExploreSectionProps = {
  currentProductId: ProductId
}

function ProductExploreCard({
  productId,
  isCurrent,
}: {
  productId: ProductId
  isCurrent: boolean
}) {
  const product = PRODUCT_REGISTRY[productId]
  const Icon = product.icon

  const cardClassName = cn(
    'group flex items-start gap-3 rounded-xl border p-4 text-start transition-colors',
    isCurrent
      ? 'border-border bg-muted/30'
      : 'border-border bg-card/45 hover:bg-accent/15',
  )

  return (
    <a
      href={product.path}
      aria-current={isCurrent ? 'page' : undefined}
      className={cardClassName}
      onClick={(event) => {
        if (!isCurrent) return
        event.preventDefault()
        scrollMarketingMainToTop('smooth')
      }}
    >
      <span className="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
        <Icon className="size-4 text-muted-foreground" aria-hidden />
      </span>
      <div className="min-w-0 flex-1">
        <h3 className="text-[14px] font-semibold text-foreground">{product.name}</h3>
        <p className="mt-1 text-[13px] leading-5 text-muted-foreground">{product.tagline}</p>
      </div>
      {!isCurrent ? (
        <ArrowRight
          className="size-4 shrink-0 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5"
          aria-hidden
        />
      ) : null}
    </a>
  )
}

export function ProductExploreSection({ currentProductId }: ProductExploreSectionProps) {
  return (
    <section className="border-t border-border bg-muted/20 py-16 sm:py-20">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <MarketingSectionHeading
          title="Explore Appwrite"
          description="Modular backend services that share the same project, permissions model, and console."
          size="md"
        />

        <div className="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {PRODUCT_IDS.map((productId) => (
            <ProductExploreCard
              key={productId}
              productId={productId}
              isCurrent={productId === currentProductId}
            />
          ))}
        </div>
      </div>
    </section>
  )
}
