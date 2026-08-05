import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  PRODUCT_NAV_REGISTRY,
  isProductNavItemComingSoon,
} from '@/lib/products/registry'
import type { ProductNavItemId } from '@/lib/products/types'

export type MarketingProductToolkitItem = {
  label: string
  href: string
}

const MARKETING_TOOLKIT_NAV_IDS = {
  build: [
    'auth',
    'databases',
    'storage',
    'functions',
    'messaging',
    'realtime',
    'agent',
  ],
  deploy: ['sites'],
  protect: ['firewall', 'advisor'],
} as const satisfies Record<
  'build' | 'deploy' | 'protect',
  readonly ProductNavItemId[]
>

function isToolkitNavItemVisible(id: ProductNavItemId): boolean {
  if (isProductNavItemComingSoon(id)) return false
  if (id === 'agent') return getActiveProfileFeatures().agent
  return true
}

function toToolkitItems(ids: readonly ProductNavItemId[]) {
  return ids.filter(isToolkitNavItemVisible).map((id) => {
    const item = PRODUCT_NAV_REGISTRY[id]
    return { label: item.name, href: item.href }
  })
}

function getMarketingProductToolkit() {
  return {
    build: toToolkitItems(MARKETING_TOOLKIT_NAV_IDS.build),
    deploy: toToolkitItems(MARKETING_TOOLKIT_NAV_IDS.deploy),
    protect: toToolkitItems(MARKETING_TOOLKIT_NAV_IDS.protect),
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
