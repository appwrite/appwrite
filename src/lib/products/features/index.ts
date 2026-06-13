import { authProductFeatures } from '@/lib/products/features/auth'
import type { ProductFeatureContent } from '@/lib/products/features/types'
import type { ProductId } from '@/lib/products/types'

export function getProductFeatures(productId: ProductId): ProductFeatureContent[] | null {
  switch (productId) {
    case 'auth':
      return authProductFeatures
    default:
      return null
  }
}
