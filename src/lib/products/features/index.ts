import { authProductFeatures } from '@/lib/products/features/auth'
import { databasesProductFeatures } from '@/lib/products/features/databases'
import { firewallProductFeatures } from '@/lib/products/features/firewall'
import { functionsProductFeatures } from '@/lib/products/features/functions'
import { messagingProductFeatures } from '@/lib/products/features/messaging'
import { sitesProductFeatures } from '@/lib/products/features/sites'
import { storageProductFeatures } from '@/lib/products/features/storage'
import type { ProductFeatureContent } from '@/lib/products/features/types'
import type { ProductId } from '@/lib/products/types'

export function getProductFeatures(productId: ProductId): ProductFeatureContent[] | null {
  switch (productId) {
    case 'auth':
      return authProductFeatures
    case 'databases':
      return databasesProductFeatures
    case 'storage':
      return storageProductFeatures
    case 'functions':
      return functionsProductFeatures
    case 'messaging':
      return messagingProductFeatures
    case 'sites':
      return sitesProductFeatures
    case 'firewall':
      return firewallProductFeatures
    default:
      return null
  }
}
