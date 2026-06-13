import { MarketingSectionHeading } from '@/components/pages/marketing/MarketingSections'
import { ProductStoryVisual } from '@/components/pages/products/product-story/ProductStoryVisual'
import type { ProductId } from '@/lib/products/types'

type ProductStorySectionProps = {
  productId: ProductId
  title: string
  description: string
}

export function ProductStorySection({
  productId,
  title,
  description,
}: ProductStorySectionProps) {
  return (
    <section className="border-b border-border bg-muted/10 py-12 sm:py-16 lg:py-20">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <MarketingSectionHeading align="center" size="md" title={title} description={description} />
        <div className="mt-8 sm:mt-10">
          <ProductStoryVisual productId={productId} />
        </div>
      </div>
    </section>
  )
}
