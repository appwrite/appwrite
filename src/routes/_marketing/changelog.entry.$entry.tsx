import { createFileRoute, notFound } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { ChangelogSeenSync } from '@/components/pages/changelog/ChangelogSeenSync'
import { DetailView } from '@/components/pages/changelog/DetailView'
import {
  getChangelogEntry,
  getChangelogMarkdownExport,
} from '@/lib/changelog/content'
import {
  getChangelogEntryMetaTags,
  getChangelogEntrySchema,
} from '@/lib/changelog/seo'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'
import { CHANGELOG_RSS_PATH } from '@/lib/seo/rss'
import { trackServerPageview } from '@/lib/server-analytics'

export const Route = createFileRoute('/_marketing/changelog/entry/$entry')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ params, request, next }) => {
        const slug = params.entry
        if (!slug.endsWith('.md')) {
          return next()
        }

        const markdown = getChangelogMarkdownExport(slug.slice(0, -3))
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
  loader: async ({ context, params }) => {

    if (params.entry.endsWith('.md')) {
      throw notFound()
    }

    const entry = getChangelogEntry(params.entry)
    if (!entry) {
      throw notFound()
    }

    return { entry }
  },
  head: ({ loaderData }) => {
    if (!loaderData?.entry) return {}

    return {
      meta: getChangelogEntryMetaTags(loaderData.entry, {
        siteOrigin: getRequestSiteOrigin(),
      }),
      links: [
        {
          rel: 'alternate',
          type: 'application/rss+xml',
          title: 'Appwrite Changelog',
          href: CHANGELOG_RSS_PATH,
        },
        {
          rel: 'alternate',
          type: 'text/markdown',
          href: `${loaderData.entry.href}.md`,
        },
      ],
      scripts: [
        {
          type: 'application/ld+json',
          children: JSON.stringify(getChangelogEntrySchema(loaderData.entry)),
        },
      ],
    }
  },
  component: ChangelogEntryPage,
})

function ChangelogEntryPage() {
  const { entry } = Route.useLoaderData()

  return (
    <>
      <ChangelogSeenSync />
      <DetailView entry={entry} />
    </>
  )
}
