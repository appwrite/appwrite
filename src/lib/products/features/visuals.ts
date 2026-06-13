import type { ComponentType } from 'react'
import { AUTH_FEATURE_VISUALS } from '@/components/pages/products/features/auth'
import type { ProductId } from '@/lib/products/types'

const PRODUCT_FEATURE_VISUALS: Partial<
  Record<ProductId, Record<string, ComponentType>>
> = {
  auth: AUTH_FEATURE_VISUALS,
}

export function getProductFeatureVisual(
  productId: ProductId,
  featureId: string,
): ComponentType | undefined {
  return PRODUCT_FEATURE_VISUALS[productId]?.[featureId]
}
