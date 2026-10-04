'use client'

import { ArrowRight } from 'lucide-react'
import { MarketingSectionHeading } from '@/components/pages/marketing/MarketingSections'
import { ProductIconTile } from '@/components/pages/products/_components/ProductTone'
import { scrollMarketingMainToTop } from '@/lib/marketing/MarketingScrollToTop'
import {
  PRODUCT_NAV_REGISTRY,
  PRODUCT_PAGE_EXPLORE_NAV_ITEM_IDS,
  PRODUCT_REGISTRY,
  isProductId,
} from '@/lib/products/registry'
import type { ProductId, ProductNavItemId } from '@/lib/products/types'
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
  postgresTagline: string
  storageTagline: string
  functionsTagline: string
  messagingTagline: string
  realtimeTagline: string
  sitesTagline: string
  domainsName: string
  domainsTagline: string
  firewallName: string
  firewallTagline: string
}

function getExploreNavItemName(
  navItemId: ProductNavItemId,
  productNamesCopy: ProductNamesCopy,
  navigationItemsCopy: ProductNavigationItemsCopy,
): string {
  if (navItemId === 'domains') return navigationItemsCopy.domainsName
  if (navItemId === 'firewall') return navigationItemsCopy.firewallName
  if (isProductId(navItemId)) {
    return productNamesCopy[navItemId] ?? PRODUCT_REGISTRY[navItemId].name
  }
  return PRODUCT_NAV_REGISTRY[navItemId].name
}

function getExploreNavItemTagline(
  navItemId: ProductNavItemId,
  navigationItemsCopy: ProductNavigationItemsCopy,
): string {
  const taglineById: Partial<Record<ProductNavItemId, string>> = {
    auth: navigationItemsCopy.authTagline,
    databases: navigationItemsCopy.databasesTagline,
    postgres: navigationItemsCopy.postgresTagline,
    storage: navigationItemsCopy.storageTagline,
    functions: navigationItemsCopy.functionsTagline,
    messaging: navigationItemsCopy.messagingTagline,
    realtime: navigationItemsCopy.realtimeTagline,
    sites: navigationItemsCopy.sitesTagline,
    domains: navigationItemsCopy.domainsTagline,
    firewall: navigationItemsCopy.firewallTagline,
  }
  return taglineById[navItemId] ?? PRODUCT_NAV_REGISTRY[navItemId].tagline
}

function ProductExploreCard({
  navItemId,
  isCurrent,
  productNamesCopy,
  productNavigationItemsCopy,
}: {
  navItemId: ProductNavItemId
  isCurrent: boolean
  productNamesCopy: ProductNamesCopy
  productNavigationItemsCopy: ProductNavigationItemsCopy
}) {
  const navItem = PRODUCT_NAV_REGISTRY[navItemId]
  const Icon = navItem.icon
  const productName = getExploreNavItemName(
    navItemId,
    productNamesCopy,
    productNavigationItemsCopy,
  )
  const productTagline = getExploreNavItemTagline(navItemId, productNavigationItemsCopy)

  const cardClassName = cn(
    'group relative isolate flex items-start gap-3 overflow-hidden rounded-xl border p-4 text-start transition-[border-color,background-color,transform] duration-300 motion-reduce:transition-none',
    isCurrent
      ? 'border-[rgb(var(--tone-rgb)/0.45)] bg-card/60'
      : 'border-border bg-card/45 hover:-translate-y-0.5 hover:border-[rgb(var(--tone-rgb)/0.45)] motion-reduce:hover:translate-y-0',
  )

  return (
    <a
      href={navItem.href}
      aria-current={isCurrent ? 'page' : undefined}
      className={cardClassName}
      onClick={(event) => {
        if (!isCurrent) return
        event.preventDefault()
        scrollMarketingMainToTop('smooth')
      }}
    >
      <span
        className={cn(
          'product-tone-glow pointer-events-none absolute -start-1/3 -top-full -z-10 h-[260%] w-[90%] transition-opacity duration-300',
          isCurrent ? 'opacity-100' : 'opacity-0 group-hover:opacity-100',
        )}
        aria-hidden
      />
      <ProductIconTile icon={Icon} size="sm" />
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
          className="size-4 shrink-0 text-muted-foreground/50 transition-[transform,color] group-hover:translate-x-0.5 group-hover:text-[var(--tone-ink)] rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5"
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
          {PRODUCT_PAGE_EXPLORE_NAV_ITEM_IDS.map((navItemId) => (
            <div
              key={navItemId}
              className="w-full sm:w-[calc((100%-0.75rem)/2)] lg:w-[calc((100%-1.5rem)/3)]"
            >
              <ProductExploreCard
                navItemId={navItemId}
                isCurrent={isProductId(navItemId) && navItemId === currentProductId}
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
