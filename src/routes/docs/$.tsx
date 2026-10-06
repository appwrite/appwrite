import { createFileRoute, notFound, redirect } from '@tanstack/react-router'
import { NotFoundView } from '@/components/error/NotFound'
import { View } from '@/components/pages/docs/View'
import { getDocsMarkdownExport, getDocsPage } from '@/lib/docs/content'
import { isFeatureGatedDocsSlugHidden } from '@/lib/docs/feature-gated-docs'
import { getDocsRedirectTarget } from '@/lib/docs/redirects'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { generateDocsLlmsTxt } from '@/lib/seo/llms-content'
import { trackServerPageview } from '@/lib/server-analytics'
import { getDocsRouteHead } from '@/lib/docs/route-meta'
import {
  getDocsArticleSchema,
  getDocsBreadcrumbSchema,
  getDocsFaqSchema,
} from '@/lib/docs/seo'
import { stringifyJsonLd } from '@/lib/seo/json-ld'

const DOCS_LLMS_TXT_SPLAT = 'llms.txt'

export const Route = createFileRoute('/docs/$')({
  ssr: true,
  notFoundComponent: NotFoundView,
  server: {
    handlers: {
      GET: async ({ params, request, next }) => {
        const splat = params._splat ?? ''

        if (splat === DOCS_LLMS_TXT_SPLAT) {
          trackServerPageview(request)
          return respondWithPrebuiltOrRuntime(
            'docs/llms.txt',
            'text/markdown; charset=utf-8',
            () => generateDocsLlmsTxt(),
          )
        }

        if (!splat.endsWith('.md')) {
          return next()
        }

        const slug = splat.slice(0, -3)
        if (isFeatureGatedDocsSlugHidden(slug)) {
          return new Response('Not found', { status: 404 })
        }

        const markdown = await getDocsMarkdownExport(slug)
        if (!markdown) {
          return new Response('Not found', { status: 404 })
        }

        trackServerPageview(request)

        return new Response(markdown, {
          headers: {
            'Content-Type': 'text/markdown; charset=utf-8',
            'Cache-Control': 'public, max-age=3600',
          },
        })
      },
    },
  },
  beforeLoad: ({ params }) => {
    const splat = params._splat ?? ''
    if (splat === DOCS_LLMS_TXT_SPLAT || splat.endsWith('.md')) return

    if (isFeatureGatedDocsSlugHidden(splat, { deferPartnersOnServer: true })) {
      throw redirect({ to: '/docs', replace: true })
    }
  },
  loader: async ({ params }) => {
    const splat = params._splat ?? ''
    if (splat === DOCS_LLMS_TXT_SPLAT || splat.endsWith('.md')) {
      throw notFound()
    }

    const redirectTarget = getDocsRedirectTarget(splat)
    if (redirectTarget) {
      const targetSlug = redirectTarget.pathname
        .replace(/^\/docs\/?/, '')
        .replace(/\/+$/, '')
      if (
        isFeatureGatedDocsSlugHidden(targetSlug, {
          deferPartnersOnServer: true,
        })
      ) {
        throw redirect({ to: '/docs', replace: true })
      }
      throw redirect({
        to: redirectTarget.pathname,
        hash: redirectTarget.hash,
        replace: true,
      })
    }

    const page = await getDocsPage(splat)
    if (!page) {
      throw notFound()
    }

    return { page }
  },
  head: ({ loaderData }) => {
    if (!loaderData?.page) return {}
    const { meta, slug } = { meta: loaderData.page.meta, slug: loaderData.page.meta.slug }
    const seo = getDocsRouteHead({ ...meta, slug })
    return {
      ...seo,
      links: [
        ...seo.links,
        ...(slug
          ? [
              {
                rel: 'alternate',
                type: 'text/markdown',
                href: `/docs/${slug}.md`,
              },
            ]
          : []),
      ],
      scripts: [
        {
          type: 'application/ld+json',
          children: stringifyJsonLd(getDocsBreadcrumbSchema(meta, slug)),
        },
        {
          type: 'application/ld+json',
          children: stringifyJsonLd(getDocsArticleSchema(meta, slug)),
        },
        ...(meta.faqs?.length
          ? [
              {
                type: 'application/ld+json' as const,
                children: stringifyJsonLd(getDocsFaqSchema(meta.faqs)),
              },
            ]
          : []),
      ],
    }
  },
  component: DocsArticlePage,
})

function DocsArticlePage() {
  const { page } = Route.useLoaderData()
  return <View page={page} />
}
