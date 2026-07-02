import { createFileRoute, notFound } from '@tanstack/react-router'
import { AuthorView } from '@/components/pages/threads/AuthorView'
import { getAuthor, getAuthorThreads } from '@/lib/threads/content'
import { getThreadsAuthorRouteMetaTags } from '@/lib/threads/route-meta'
import {
  getThreadsAuthorPageSchema,
  getThreadsBreadcrumbSchema,
  getThreadsCanonicalUrl,
} from '@/lib/threads/seo'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'

export const Route = createFileRoute('/_marketing/threads/authors/$authorId')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  loader: async ({ context, params }) => {

    let author
    try {
      author = await getAuthor(params.authorId)
    } catch {
      throw notFound()
    }

    let threads: Awaited<ReturnType<typeof getAuthorThreads>>['threads'] = []
    let total = 0

    try {
      ;({ threads, total } = await getAuthorThreads(params.authorId))
    } catch {
      // Author page can still render when thread list fails.
    }

    const canonicalUrl = getThreadsCanonicalUrl(
      `/threads/authors/${params.authorId}`,
    )

    return {
      author,
      threads,
      total,
      canonicalUrl,
    }
  },
  head: ({ loaderData }) => {
    if (!loaderData?.author) return {}

    const { author, canonicalUrl } = loaderData

    return {
      meta: getThreadsAuthorRouteMetaTags(author, canonicalUrl),
      scripts: [
        {
          type: 'application/ld+json',
          children: JSON.stringify(
            getThreadsAuthorPageSchema(author, canonicalUrl),
          ),
        },
        {
          type: 'application/ld+json',
          children: JSON.stringify(
            getThreadsBreadcrumbSchema([
              { name: 'Threads', path: '/threads' },
              {
                name: author.display_name,
                path: `/threads/authors/${author.discord_id}`,
              },
            ]),
          ),
        },
      ],
    }
  },
  component: ThreadsAuthorPage,
})

function ThreadsAuthorPage() {
  const pageData = Route.useLoaderData()

  return (<AuthorView {...pageData} />
    )
}
