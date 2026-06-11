import { createFileRoute, notFound, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/docs/View'
import { getDocsMarkdownExport, getDocsPage } from '@/lib/docs/content'
import { getDocsRedirectTarget } from '@/lib/docs/redirects'
import { getDocsMetaTags } from '@/lib/docs/route-meta'
import {
  getDocsArticleSchema,
  getDocsBreadcrumbSchema,
} from '@/lib/docs/seo'

export const Route = createFileRoute('/docs/$')({
  ssr: true,
  server: {
    handlers: {
      GET: async ({ params, next }) => {
        const splat = params._splat ?? ''
        if (!splat.endsWith('.md')) {
          return next()
        }

        const slug = splat.slice(0, -3)
        const markdown = getDocsMarkdownExport(slug)
        if (!markdown) {
          return new Response('Not found', { status: 404 })
        }

        return new Response(markdown, {
          headers: {
            'Content-Type': 'text/markdown; charset=utf-8',
            'Cache-Control': 'public, max-age=3600',
          },
        })
      },
    },
  },
  loader: ({ params }) => {
    const splat = params._splat ?? ''
    if (splat.endsWith('.md')) {
      throw notFound()
    }

    const redirectTarget = getDocsRedirectTarget(splat)
    if (redirectTarget) {
      throw redirect({
        to: redirectTarget.pathname,
        hash: redirectTarget.hash,
        replace: true,
      })
    }

    const page = getDocsPage(splat)
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
