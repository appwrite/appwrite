import { createFileRoute, notFound } from '@tanstack/react-router'
import { View } from '@/components/pages/secret/View'
import { getSecretVariantContent } from '@/lib/campaigns/secret/content'
import { getSecretCampaignPath, isSecretCampaignVariant } from '@/lib/campaigns/secret/registry'
import { translate } from '@/lib/i18n/translate'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { getMarketingRouteHead } from '@/lib/marketing/route-meta'
import { NOINDEX_FOLLOW_ROBOTS_META } from '@/lib/seo/indexing'

/** Paid social landing pages. Noindex so they never compete with /alternative-to pages in search. */
export const Route = createFileRoute('/_marketing/secret/$variant')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  beforeLoad: ({ params }) => {
    if (!isSecretCampaignVariant(params.variant)) {
      throw notFound()
    }
  },
  loader: ({ params }) => {
    if (!isSecretCampaignVariant(params.variant)) {
      throw notFound()
    }
    return { variant: params.variant }
  },
  head: ({ params }) => {
    if (!isSecretCampaignVariant(params.variant)) return {}
    const content = getSecretVariantContent(params.variant)
    const pageName = translate(content.metaTitle)
    const description = translate(content.metaDescription)
    const seo = getMarketingRouteHead({
      canonicalPath: getSecretCampaignPath(params.variant),
      pageName,
      description,
      ogImageEyebrow: translate('Case file, declassified'),
      ogImageTitle: pageName,
      ogImageSubtitle: translate('Read the fine print, then make your own call.'),
    })
    return { ...seo, meta: [...seo.meta, NOINDEX_FOLLOW_ROBOTS_META] }
  },
  component: SecretCampaignPage,
})

function SecretCampaignPage() {
  const { variant } = Route.useLoaderData()
  return <View variant={variant} />
}
