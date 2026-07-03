import { createFileRoute, notFound } from '@tanstack/react-router'
import { ProductPageLayout } from '@/components/pages/products/ProductPageLayout'
import { getProductContent } from '@/lib/products/content'
import { isProductId, PRODUCT_REGISTRY } from '@/lib/products/registry'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'
import {
  marketingSiteTemplatesQueryOptions,
  siteFrameworksQueryOptions,
} from '@/lib/react-query/hooks/sites'
import { MARKETING_SITE_TEMPLATES_PROJECT_ID } from '@/lib/sites/site-template-wizard'
import { pageTitle } from '@/lib/utils/page-title'
import { translate } from '@/lib/i18n/translate'

export const Route = createFileRoute('/_marketing/products/$productId')({
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
    const product = PRODUCT_REGISTRY[params.productId]
    const metaDescription = translate(content.metaDescription)
    const ogImageSubtitle =
      content.metaDescription.trim() !== product.name.trim()
        ? metaDescription
        : translate(product.tagline)

    return {
      meta: getMarketingPageMetaTags({
        pageName: product.name,
        description: metaDescription,
        ogImageEyebrow: 'Products',
        ogImageSubtitle,
      }),
    }
  },
  loader: async ({ params, context }) => {

    if (typeof window !== 'undefined' && params.productId === 'sites') {
      const { queryClient } = context
      await Promise.all([
        queryClient
          .ensureQueryData(
            siteFrameworksQueryOptions(MARKETING_SITE_TEMPLATES_PROJECT_ID),
          )
          .catch(() => {}),
        queryClient.ensureQueryData(marketingSiteTemplatesQueryOptions()).catch(
          () => {},
        ),
      ])
    }
  },
  component: ProductPage,
})

function ProductPage() {
  const { productId } = Route.useParams()

  if (!isProductId(productId)) {
    throw notFound()
  }

  const content = getProductContent(productId)

  return (<ProductPageLayout content={content} />
    )
}
