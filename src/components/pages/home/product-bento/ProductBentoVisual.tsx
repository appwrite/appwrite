import type { ComponentType } from 'react'
import { AuthProductVisual } from './AuthProductVisual'
import { DatabasesProductVisual } from './DatabasesProductVisual'
import { FunctionsProductVisual } from './FunctionsProductVisual'
import { ProductBentoPlaceholder } from './ProductBentoPlaceholder'
import { SitesProductVisual } from './SitesProductVisual'
import { StorageProductVisual } from './StorageProductVisual'

const PRODUCT_VISUALS: Record<string, ComponentType> = {
  Auth: AuthProductVisual,
  Databases: DatabasesProductVisual,
  Functions: FunctionsProductVisual,
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
