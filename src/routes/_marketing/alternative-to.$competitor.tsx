import { createFileRoute, notFound } from '@tanstack/react-router'
import { View } from '@/components/pages/alternative-to/$competitor/View'
import { getAlternativeContent } from '@/lib/alternatives/content'
import {
  getAlternativeMarkdownExport,
  parseAlternativeRouteParam,
} from '@/lib/alternatives/markdown-export'
import { ALTERNATIVE_REGISTRY, isAlternativeId } from '@/lib/alternatives/registry'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { getMarketingRouteHead } from '@/lib/marketing/route-meta'
import { translate } from '@/lib/i18n/translate'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { stringifyJsonLd } from '@/lib/seo/json-ld'
import { trackServerPageview } from '@/lib/server-analytics'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_marketing/alternative-to/$competitor')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ params, request, next }) => {
        if (!params.competitor.endsWith('.md')) {
          return next()
        }

        const markdown = getAlternativeMarkdownExport(params.competitor)
        if (!markdown) {
          return new Response('Not found', { status: 404 })
        }

        trackServerPageview(request)

        const id = parseAlternativeRouteParam(params.competitor)
        return respondWithPrebuiltOrRuntime(
          id ? `alternative-to/${id}.md` : params.competitor,
          'text/markdown; charset=utf-8',
          () => markdown,
        )
      },
    },
  },
  beforeLoad: ({ params }) => {
    if (!parseAlternativeRouteParam(params.competitor)) {
      throw notFound()
    }
  },
  loader: async ({ params }) => {
    if (params.competitor.endsWith('.md')) {
      throw notFound()
    }

    const id = parseAlternativeRouteParam(params.competitor)
    if (!id) {
      throw notFound()
    }

    return { competitor: id }
  },
  head: ({ params, loaderData }) => {
    const id = loaderData?.competitor ?? parseAlternativeRouteParam(params.competitor)
    if (!id || !isAlternativeId(id)) {
      return { meta: [{ title: pageTitle('Compare') }] }
    }

    const meta = ALTERNATIVE_REGISTRY[id]
    const content = getAlternativeContent(id)
    const pageName = translate(meta.metaTitle)
    const description = translate(meta.metaDescription)
    const canonicalPath = `/alternative-to/${id}`
    const seo = getMarketingRouteHead({
      canonicalPath,
      pageName,
      description,
      ogImageEyebrow: `Appwrite vs ${meta.name}`,
      ogImageTitle: pageName,
      ogImageSubtitle: description,
    })

    return {
      ...seo,
      links: [
        ...(seo.links ?? []),
        {
          rel: 'alternate',
          type: 'text/markdown',
          href: `${canonicalPath}.md`,
        },
      ],
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
  const { competitor: routeParam } = Route.useParams()
  const loaderData = Route.useLoaderData()
  const competitor = loaderData?.competitor ?? parseAlternativeRouteParam(routeParam)

  if (!competitor || !isAlternativeId(competitor)) {
    throw notFound()
  }

  return <View competitor={competitor} />
}
