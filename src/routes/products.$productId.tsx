import { createFileRoute, notFound } from '@tanstack/react-router'
import { ProductPageLayout } from '@/components/pages/products/ProductPageLayout'
import { getProductContent } from '@/lib/products/content'
import { isProductId, PRODUCT_REGISTRY } from '@/lib/products/registry'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/products/$productId')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  beforeLoad: ({ params }) => {
    if (!isProductId(params.productId)) {
      throw notFound()
    }
  },
  head: ({ params }) => {
    if (!isProductId(params.productId)) {
      return { meta: [{ title: pageTitle('Product') }] }
    }

    const content = getProductContent(params.productId)
    const productName = PRODUCT_REGISTRY[params.productId].name
    return {
      meta: [
        { title: pageTitle(productName) },
        { name: 'description', content: content.metaDescription },
      ],
    }
  },
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
  },
  component: ProductPage,
})

function ProductPage() {
  const { productId } = Route.useParams()

  if (!isProductId(productId)) {
    throw notFound()
  }

  const content = getProductContent(productId)

  return (
    <MarketingPageShell>
      <ProductPageLayout content={content} />
    </MarketingPageShell>
  )
}
