import { ProductFeatureSection } from '@/components/pages/products/ProductFeatureSection'
import { MessagingProviderCatalog } from '@/components/pages/products/features/messaging/MessagingProviderCatalog'
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
        const companion =
          productId === 'messaging' && feature.id === 'providers' ? (
            <MessagingProviderCatalog />
          ) : undefined

        if (!Visual && !companion && !feature.hideVisual) return null

        return (
          <ProductFeatureSection
            key={feature.id}
            feature={feature}
            index={index}
            visual={Visual ? <Visual /> : undefined}
            companion={companion}
          />
        )
      })}
    </>
  )
}
