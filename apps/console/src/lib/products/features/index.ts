import { analyticsProductFeatures } from '@/lib/products/features/analytics'
import { authProductFeatures } from '@/lib/products/features/auth'
import { beepProductFeatures } from '@/lib/products/features/beep'
import { databasesProductFeatures } from '@/lib/products/features/databases'
import { firewallProductFeatures } from '@/lib/products/features/firewall'
import { functionsProductFeatures } from '@/lib/products/features/functions'
import { messagingProductFeatures } from '@/lib/products/features/messaging'
import { monitorsProductFeatures } from '@/lib/products/features/monitors'
import { postgresProductFeatures } from '@/lib/products/features/postgres'
import { realtimeProductFeatures } from '@/lib/products/features/realtime'
import { reviewsProductFeatures } from '@/lib/products/features/reviews'
import { sitesProductFeatures } from '@/lib/products/features/sites'
import { storageProductFeatures } from '@/lib/products/features/storage'
import type { ProductFeatureContent } from '@/lib/products/features/types'
import { videosProductFeatures } from '@/lib/products/features/videos'
import type { ProductId } from '@/lib/products/types'

export function getProductFeatures(productId: ProductId): ProductFeatureContent[] | null {
  switch (productId) {
    case 'auth':
      return authProductFeatures
    case 'databases':
      return databasesProductFeatures
    case 'postgres':
      return postgresProductFeatures
    case 'storage':
      return storageProductFeatures
    case 'functions':
      return functionsProductFeatures
    case 'messaging':
      return messagingProductFeatures
    case 'videos':
      return videosProductFeatures
    case 'realtime':
      return realtimeProductFeatures
    case 'sites':
      return sitesProductFeatures
    case 'analytics':
      return analyticsProductFeatures
    case 'firewall':
      return firewallProductFeatures
    case 'monitors':
      return monitorsProductFeatures
    case 'beep':
      return beepProductFeatures
    case 'reviews':
      return reviewsProductFeatures
    default:
      return null
  }
}
