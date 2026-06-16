import { createFileRoute, notFound } from '@tanstack/react-router'
import { ThreadDetailView } from '@/components/pages/threads/ThreadDetailView'
import {
  getRelatedThreads,
  getThread,
  getThreadMessages,
} from '@/lib/threads/content'
import { getThreadsThreadRouteMetaTags } from '@/lib/threads/route-meta'
import {
  getDiscussionForumPageSchema,
  getThreadsBreadcrumbSchema,
  getThreadsCanonicalUrl,
} from '@/lib/threads/seo'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'

export const Route = createFileRoute('/threads/$threadId')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  loader: async ({ context, params }) => {
    await marketingPageLoader(context.queryClient)

    let thread
    try {
      thread = await getThread(params.threadId)
    } catch {
      throw notFound()
    }

    const [messages, related] = await Promise.all([
      getThreadMessages(params.threadId),
      getRelatedThreads(thread),
    ])

    const canonicalUrl = getThreadsCanonicalUrl(`/threads/${params.threadId}`)

    return {
      thread,
      messages,
      related,
      canonicalUrl,
    }
  },
  head: ({ loaderData }) => {
    if (!loaderData?.thread) return {}

    const { thread, messages, canonicalUrl } = loaderData

    return {
      meta: getThreadsThreadRouteMetaTags(thread, canonicalUrl),
      scripts: [
        {
          type: 'application/ld+json',
          children: JSON.stringify(
            getDiscussionForumPageSchema({
              canonicalUrl,
              thread,
              messages,
            }),
          ),
        },
        {
          type: 'application/ld+json',
          children: JSON.stringify(
            getThreadsBreadcrumbSchema([
              { name: 'Threads', path: '/threads' },
              { name: thread.title, path: `/threads/${thread.discord_id}` },
            ]),
          ),
        },
      ],
    }
  },
  component: ThreadsDetailPage,
})

function ThreadsDetailPage() {
  const pageData = Route.useLoaderData()

  return (
    <MarketingPageShell>
      <ThreadDetailView {...pageData} />
    </MarketingPageShell>
  )
}
