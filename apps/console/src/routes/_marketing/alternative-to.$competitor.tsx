import { createFileRoute, notFound } from '@tanstack/react-router'
import { View } from '@/components/pages/alternative-to/$competitor/View'
import { isAlternativeReleased } from '@/lib/alternatives/availability'
import { getAlternativeContent } from '@/lib/alternatives/content'
import {
  ALTERNATIVE_REGISTRY,
  isAlternativeId,
  parseAlternativeRouteParam,
} from '@/lib/alternatives/registry'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import {
  getMarketingFaqScript,
  getMarketingRouteHead,
} from '@/lib/marketing/route-meta'
import { getProfileFeaturesForCookieHeader } from '@/lib/console-profiles'
import { translate } from '@/lib/i18n/translate'
import { getProductPageFeatures } from '@/lib/products/availability-ssr'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
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

        // A comparison that launches with a product stays hidden until then.
        const id = parseAlternativeRouteParam(params.competitor)
        if (
          id &&
          !isAlternativeReleased(
            id,
            getProfileFeaturesForCookieHeader(request.headers.get('cookie')),
          )
        ) {
          return new Response('Not found', { status: 404 })
        }

        // Loaded here so the markdown builder stays out of the client bundle.
        const { getAlternativeMarkdownExport } = await import(
          '@/lib/alternatives/markdown-export'
        )
        const markdown = getAlternativeMarkdownExport(params.competitor)
        if (!markdown) {
          return new Response('Not found', { status: 404 })
        }

        trackServerPageview(request)

        return respondWithPrebuiltOrRuntime(
          id ? `alternative-to/${id}.md` : params.competitor,
          'text/markdown; charset=utf-8',
          () => markdown,
        )
      },
    },
  },
  beforeLoad: ({ params }) => {
    const id = parseAlternativeRouteParam(params.competitor)
    // Comparisons that launch with a product 404 until it is released.
    if (!id || !isAlternativeReleased(id, getProductPageFeatures())) {
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

    // Read here rather than in `head`: the loader ships with the page, while
    // `head` loads with the route tree on every page.
    return { competitor: id, faq: getAlternativeContent(id).faq }
  },
  head: ({ params, loaderData }) => {
    const id =
      loaderData?.competitor ?? parseAlternativeRouteParam(params.competitor)
    if (!id || !isAlternativeId(id)) {
      return { meta: [{ title: pageTitle('Compare') }] }
    }

    const meta = ALTERNATIVE_REGISTRY[id]
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
      scripts: loaderData?.faq ? [getMarketingFaqScript(loaderData.faq)] : [],
    }
  },
  component: AlternativePage,
})

function AlternativePage() {
  const { competitor: routeParam } = Route.useParams()
  const loaderData = Route.useLoaderData()
  const competitor =
    loaderData?.competitor ?? parseAlternativeRouteParam(routeParam)

  if (!competitor || !isAlternativeId(competitor)) {
    throw notFound()
  }

  return <View competitor={competitor} />
}
