import type { ComponentType } from 'react'
import { ANALYTICS_FEATURE_VISUALS } from '@/components/pages/products/features/analytics'
import { AUTH_FEATURE_VISUALS } from '@/components/pages/products/features/auth'
import {
  BEEP_FEATURE_COMPANIONS,
  BEEP_FEATURE_VISUALS,
} from '@/components/pages/products/features/beep'
import { DATABASES_FEATURE_VISUALS } from '@/components/pages/products/features/databases'
import { FIREWALL_FEATURE_VISUALS } from '@/components/pages/products/features/firewall'
import { FUNCTIONS_FEATURE_VISUALS } from '@/components/pages/products/features/functions'
import { MESSAGING_FEATURE_VISUALS } from '@/components/pages/products/features/messaging'
import {
  MONITORS_FEATURE_COMPANIONS,
  MONITORS_FEATURE_VISUALS,
} from '@/components/pages/products/features/monitors'
import { POSTGRES_FEATURE_VISUALS } from '@/components/pages/products/features/postgres'
import { REALTIME_FEATURE_VISUALS } from '@/components/pages/products/features/realtime'
import {
  REVIEWS_FEATURE_COMPANIONS,
  REVIEWS_FEATURE_VISUALS,
} from '@/components/pages/products/features/reviews'
import { SITES_FEATURE_VISUALS } from '@/components/pages/products/features/sites'
import { STORAGE_FEATURE_VISUALS } from '@/components/pages/products/features/storage'
import { VIDEOS_FEATURE_VISUALS } from '@/components/pages/products/features/videos'
import type { ProductId } from '@/lib/products/types'

const PRODUCT_FEATURE_VISUALS: Partial<
  Record<ProductId, Record<string, ComponentType>>
> = {
  analytics: ANALYTICS_FEATURE_VISUALS,
  auth: AUTH_FEATURE_VISUALS,
  beep: BEEP_FEATURE_VISUALS,
  databases: DATABASES_FEATURE_VISUALS,
  firewall: FIREWALL_FEATURE_VISUALS,
  functions: FUNCTIONS_FEATURE_VISUALS,
  messaging: MESSAGING_FEATURE_VISUALS,
  monitors: MONITORS_FEATURE_VISUALS,
  postgres: POSTGRES_FEATURE_VISUALS,
  realtime: REALTIME_FEATURE_VISUALS,
  reviews: REVIEWS_FEATURE_VISUALS,
  sites: SITES_FEATURE_VISUALS,
  storage: STORAGE_FEATURE_VISUALS,
  videos: VIDEOS_FEATURE_VISUALS,
}

export function getProductFeatureVisual(
  productId: ProductId,
  featureId: string,
): ComponentType | undefined {
  return PRODUCT_FEATURE_VISUALS[productId]?.[featureId]
}

/**
 * Wide companion content for stacked sections, registered by the product's
 * own features folder. Older products wire theirs in `ProductFeatureSections`.
 */
const PRODUCT_FEATURE_COMPANIONS: Partial<
  Record<ProductId, Record<string, ComponentType>>
> = {
  beep: BEEP_FEATURE_COMPANIONS,
  monitors: MONITORS_FEATURE_COMPANIONS,
  reviews: REVIEWS_FEATURE_COMPANIONS,
}

export function getProductFeatureCompanion(
  productId: ProductId,
  featureId: string,
): ComponentType | undefined {
  return PRODUCT_FEATURE_COMPANIONS[productId]?.[featureId]
}
