import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  getMarketingProductMenuCategories,
  PRODUCT_NAV_REGISTRY,
} from '@/lib/products/registry'
import type { ProductNavItemId } from '@/lib/products/types'

export type MarketingProductToolkitItem = {
  label: string
  href: string
}

function toToolkitItems(ids: readonly ProductNavItemId[]) {
  return ids.map((id) => {
    const item = PRODUCT_NAV_REGISTRY[id]
    return { label: item.name, href: item.href }
  })
}

function getMarketingProductToolkit() {
  const categories = getMarketingProductMenuCategories({
    agent: getActiveProfileFeatures().agent,
  })
  const byId = Object.fromEntries(categories.map((c) => [c.id, c.productIds])) as Record<
    'build' | 'deploy' | 'protect',
    readonly ProductNavItemId[]
  >

  return {
    build: toToolkitItems(byId.build ?? []),
    deploy: toToolkitItems(byId.deploy ?? []),
    protect: toToolkitItems(byId.protect ?? []),
  } as const
}

export const marketingProductToolkit = {
  get build() {
    return getMarketingProductToolkit().build
  },
  get deploy() {
    return getMarketingProductToolkit().deploy
  },
  get protect() {
    return getMarketingProductToolkit().protect
  },
} as const satisfies {
  build: readonly MarketingProductToolkitItem[]
  deploy: readonly MarketingProductToolkitItem[]
  protect: readonly MarketingProductToolkitItem[]
}
