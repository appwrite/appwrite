import type { ComponentType } from 'react'
import { AuthProductVisual } from './AuthProductVisual'
import { DatabasesProductVisual } from './DatabasesProductVisual'
import { FirewallProductVisual } from './FirewallProductVisual'
import { FunctionsProductVisual } from './FunctionsProductVisual'
import { MessagingProductVisual } from './MessagingProductVisual'
import { ProductBentoPlaceholder } from './ProductBentoPlaceholder'
import { RealtimeProductVisual } from './RealtimeProductVisual'
import { SitesProductVisual } from './SitesProductVisual'
import { StorageProductVisual } from './StorageProductVisual'

const PRODUCT_VISUALS: Record<string, ComponentType> = {
  Auth: AuthProductVisual,
  Databases: DatabasesProductVisual,
  Firewall: FirewallProductVisual,
  Functions: FunctionsProductVisual,
  Messaging: MessagingProductVisual,
  Realtime: RealtimeProductVisual,
  Sites: SitesProductVisual,
  Storage: StorageProductVisual,
}

export function ProductBentoVisual({ title }: { title: string }) {
  const Visual = PRODUCT_VISUALS[title]

  if (Visual) {
    return <Visual />
  }

  return <ProductBentoPlaceholder />
}
