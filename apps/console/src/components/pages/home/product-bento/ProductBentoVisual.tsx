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

type ProductBentoVisualId =
  | 'auth'
  | 'databases'
  | 'storage'
  | 'functions'
  | 'sites'
  | 'messaging'
  | 'firewall'
  | 'realtime'

const PRODUCT_VISUALS: Record<ProductBentoVisualId, ComponentType> = {
  auth: AuthProductVisual,
  databases: DatabasesProductVisual,
  firewall: FirewallProductVisual,
  functions: FunctionsProductVisual,
  messaging: MessagingProductVisual,
  realtime: RealtimeProductVisual,
  sites: SitesProductVisual,
  storage: StorageProductVisual,
}

export function ProductBentoVisual({
  productId,
}: {
  productId: ProductBentoVisualId
}) {
  const Visual = PRODUCT_VISUALS[productId]

  if (Visual) {
    return <Visual />
  }

  return <ProductBentoPlaceholder />
}
