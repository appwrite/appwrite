import type { ComponentType } from 'react'
import { FirewallHeroVisual } from '@/components/pages/products/features/firewall/FirewallHeroVisual'
import type { ProductId } from '@/lib/products/types'

/** Optional animated hero footer visuals (replaces or sits instead of stats). */
export const PRODUCT_HERO_VISUALS: Partial<Record<ProductId, ComponentType>> = {
  firewall: FirewallHeroVisual,
}
