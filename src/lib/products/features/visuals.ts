import type { ComponentType } from 'react'
import { AUTH_FEATURE_VISUALS } from '@/components/pages/products/features/auth'
import { DATABASES_FEATURE_VISUALS } from '@/components/pages/products/features/databases'
import { FIREWALL_FEATURE_VISUALS } from '@/components/pages/products/features/firewall'
import { FUNCTIONS_FEATURE_VISUALS } from '@/components/pages/products/features/functions'
import { MESSAGING_FEATURE_VISUALS } from '@/components/pages/products/features/messaging'
import { SITES_FEATURE_VISUALS } from '@/components/pages/products/features/sites'
import { STORAGE_FEATURE_VISUALS } from '@/components/pages/products/features/storage'
import type { ProductId } from '@/lib/products/types'

const PRODUCT_FEATURE_VISUALS: Partial<
  Record<ProductId, Record<string, ComponentType>>
> = {
  auth: AUTH_FEATURE_VISUALS,
  databases: DATABASES_FEATURE_VISUALS,
  firewall: FIREWALL_FEATURE_VISUALS,
  functions: FUNCTIONS_FEATURE_VISUALS,
  messaging: MESSAGING_FEATURE_VISUALS,
  sites: SITES_FEATURE_VISUALS,
  storage: STORAGE_FEATURE_VISUALS,
}

export function getProductFeatureVisual(
  productId: ProductId,
  featureId: string,
): ComponentType | undefined {
  return PRODUCT_FEATURE_VISUALS[productId]?.[featureId]
}
