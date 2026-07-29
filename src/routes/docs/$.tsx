import { createFileRoute, notFound, redirect } from '@tanstack/react-router'
import { NotFoundView } from '@/components/error/NotFound'
import { View } from '@/components/pages/docs/View'
import { getDocsMarkdownExport, getDocsPage } from '@/lib/docs/content'
import {
  isFirewallDocsEnabled,
  isFirewallDocsSlug,
} from '@/lib/docs/firewall-docs-feature'
import { isPartnersDocsEnabled, isPartnersDocsSlug } from '@/lib/docs/partners-docs-feature'
import { getDocsRedirectTarget } from '@/lib/docs/redirects'
import { trackServerPageview } from '@/lib/server-analytics'
import { getDocsMetaTags } from '@/lib/docs/route-meta'
import {
  getDocsArticleSchema,
  getDocsBreadcrumbSchema,
} from '@/lib/docs/seo'

function isFeatureGatedDocsSlugHidden(slug: string): boolean {
  if (isPartnersDocsSlug(slug) && !isPartnersDocsEnabled()) return true
  if (isFirewallDocsSlug(slug) && !isFirewallDocsEnabled()) return true
  return false
}

export const Route = createFileRoute('/docs/$')({
  ssr: true,
  notFoundComponent: NotFoundView,
  server: {
    handlers: {
      GET: async ({ params, request, next }) => {
        const splat = params._splat ?? ''
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
    if (splat.endsWith('.md')) return

    if (isFeatureGatedDocsSlugHidden(splat)) {
      throw redirect({ to: '/docs', replace: true })
    }
  },
  loader: async ({ params }) => {
    const splat = params._splat ?? ''
    if (splat.endsWith('.md')) {
      throw notFound()
    }

    const redirectTarget = getDocsRedirectTarget(splat)
    if (redirectTarget) {
      const targetSlug = redirectTarget.pathname
        .replace(/^\/docs\/?/, '')
        .replace(/\/+$/, '')
      if (isFeatureGatedDocsSlugHidden(targetSlug)) {
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
    return {
      meta: getDocsMetaTags({ ...meta, slug }),
      links: slug
        ? [
            {
              rel: 'alternate',
              type: 'text/markdown',
              href: `/docs/${slug}.md`,
            },
          ]
        : [],
      scripts: [
        {
          type: 'application/ld+json',
          children: JSON.stringify(getDocsBreadcrumbSchema(meta, slug)),
        },
        {
          type: 'application/ld+json',
          children: JSON.stringify(getDocsArticleSchema(meta, slug)),
        },
      ],
    }
  },
  component: DocsArticlePage,
})

function DocsArticlePage() {
  const { page } = Route.useLoaderData()
  return <View page={page} />
}
