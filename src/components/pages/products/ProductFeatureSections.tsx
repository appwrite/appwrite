import { ProductFeatureSection } from '@/components/pages/products/ProductFeatureSection'
import { getProductFeatures } from '@/lib/products/features'
import { getProductFeatureVisual } from '@/lib/products/features/visuals'
import type { ProductId } from '@/lib/products/types'

type ProductFeatureSectionsProps = {
  productId: ProductId
}

export function ProductFeatureSections({ productId }: ProductFeatureSectionsProps) {
  const features = getProductFeatures(productId)
  if (!features?.length) return null

  return (
    <>
      {features.map((feature, index) => {
        const Visual = getProductFeatureVisual(productId, feature.id)
        if (!Visual) return null

        return (
          <ProductFeatureSection
            key={feature.id}
            feature={feature}
            index={index}
            visual={<Visual />}
          />
        )
      })}
    </>
  )
}
