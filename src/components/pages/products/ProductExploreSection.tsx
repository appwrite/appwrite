'use client'

import { ArrowRight } from 'lucide-react'
import { MarketingSectionHeading } from '@/components/pages/marketing/MarketingSections'
import { scrollMarketingMainToTop } from '@/lib/marketing/MarketingScrollToTop'
import { PRODUCT_IDS, PRODUCT_REGISTRY } from '@/lib/products/registry'
import type { ProductId } from '@/lib/products/types'
import { cn } from '@/lib/utils'
import { useI18n } from '@/lib/i18n'

type ProductExploreSectionProps = {
  currentProductId: ProductId
}

type ProductNamesCopy = {
  [key in ProductId]: string
}

type ProductNavigationItemsCopy = {
  authTagline: string
  databasesTagline: string
  storageTagline: string
  functionsTagline: string
  messagingTagline: string
  sitesTagline: string
  firewallTagline: string
}

function ProductExploreCard({
  productId,
  isCurrent,
  productNamesCopy,
  productNavigationItemsCopy,
}: {
  productId: ProductId
  isCurrent: boolean
  productNamesCopy: ProductNamesCopy
  productNavigationItemsCopy: ProductNavigationItemsCopy
}) {
  const product = PRODUCT_REGISTRY[productId]
  const Icon = product.icon
  const productName = productNamesCopy[productId] ?? product.name
  const productTagline =
    productId === 'auth'
      ? productNavigationItemsCopy.authTagline
      : productId === 'databases'
        ? productNavigationItemsCopy.databasesTagline
        : productId === 'storage'
          ? productNavigationItemsCopy.storageTagline
          : productId === 'functions'
            ? productNavigationItemsCopy.functionsTagline
            : productId === 'messaging'
              ? productNavigationItemsCopy.messagingTagline
              : productId === 'firewall'
                ? productNavigationItemsCopy.firewallTagline
                : productNavigationItemsCopy.sitesTagline

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
        <h3 className="text-[14px] font-semibold text-foreground">{productName}</h3>
        <p
          className="mt-1 truncate text-[13px] leading-5 text-muted-foreground"
          title={productTagline}
        >
          {productTagline}
        </p>
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
  const { catalog } = useI18n()
  const exploreCopy = catalog.website.products.explore
  const productNamesCopy = catalog.website.products.productNames
  const productNavigationItemsCopy = catalog.website.products.navigation.items

  return (
    <section className="border-t border-border bg-muted/20 py-16 sm:py-20">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <MarketingSectionHeading
          title={exploreCopy.title}
          description={exploreCopy.description}
          size="md"
        />

        <div className="mt-10 flex flex-wrap justify-center gap-3">
          {PRODUCT_IDS.map((productId) => (
            <div
              key={productId}
              className="w-full sm:w-[calc((100%-0.75rem)/2)] lg:w-[calc((100%-1.5rem)/3)]"
            >
              <ProductExploreCard
                productId={productId}
                isCurrent={productId === currentProductId}
                productNamesCopy={productNamesCopy}
                productNavigationItemsCopy={productNavigationItemsCopy}
              />
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}
