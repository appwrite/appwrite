import { createFileRoute, notFound } from '@tanstack/react-router'
import { View } from '@/components/pages/alternative-to/$competitor/View'
import { getAlternativeContent } from '@/lib/alternatives/content'
import { ALTERNATIVE_REGISTRY, isAlternativeId } from '@/lib/alternatives/registry'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { getMarketingRouteHead } from '@/lib/marketing/route-meta'
import { translate } from '@/lib/i18n/translate'
import { stringifyJsonLd } from '@/lib/seo/json-ld'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_marketing/alternative-to/$competitor')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  beforeLoad: ({ params }) => {
    if (!isAlternativeId(params.competitor)) {
      throw notFound()
    }
  },
  head: ({ params }) => {
    if (!isAlternativeId(params.competitor)) {
      return { meta: [{ title: pageTitle('Compare') }] }
    }

    const meta = ALTERNATIVE_REGISTRY[params.competitor]
    const content = getAlternativeContent(params.competitor)
    const pageName = translate(meta.metaTitle)
    const description = translate(meta.metaDescription)

    return {
      ...getMarketingRouteHead({
        canonicalPath: `/alternative-to/${params.competitor}`,
        pageName,
        description,
        ogImageEyebrow: `Appwrite vs ${meta.name}`,
        ogImageTitle: pageName,
        ogImageSubtitle: description,
      }),
      scripts: [
        {
          type: 'application/ld+json',
          children: stringifyJsonLd({
            '@context': 'https://schema.org',
            '@type': 'FAQPage',
            mainEntity: content.faq.map((faq) => ({
              '@type': 'Question',
              name: translate(faq.question),
              acceptedAnswer: {
                '@type': 'Answer',
                text: translate(faq.answer),
              },
            })),
          }),
        },
      ],
    }
  },
  component: AlternativePage,
})

function AlternativePage() {
  const { competitor } = Route.useParams()

  if (!isAlternativeId(competitor)) {
    throw notFound()
  }

  return <View competitor={competitor} />
}
