import { createFileRoute, notFound } from '@tanstack/react-router'
import { ThreadDetailView } from '@/components/pages/threads/ThreadDetailView'
import {
  getRelatedThreads,
  getThread,
  getThreadMessages,
  getThreadPublicId,
  resolveThreadMentionLookup,
} from '@/lib/threads/content'
import { getThreadsThreadRouteHead } from '@/lib/threads/route-meta'
import {
  getDiscussionForumPageSchema,
  getThreadsBreadcrumbSchema,
  getThreadsCanonicalUrl,
} from '@/lib/threads/seo'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { stringifyJsonLd } from '@/lib/seo/json-ld'

export const Route = createFileRoute('/_marketing/threads/$threadId')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  loader: async ({ context, params }) => {

    let thread
    try {
      thread = await getThread(params.threadId)
    } catch {
      throw notFound()
    }

    let messages: Awaited<ReturnType<typeof getThreadMessages>> = []
    let related: Awaited<ReturnType<typeof getRelatedThreads>> = []
    let mentionLookup: Awaited<
      ReturnType<typeof resolveThreadMentionLookup>
    > = { users: {}, channels: {} }

    try {
      ;[messages, related] = await Promise.all([
        getThreadMessages(params.threadId),
        getRelatedThreads(thread),
      ])
    } catch {
      try {
        messages = await getThreadMessages(params.threadId)
      } catch {
        // Thread metadata still renders for crawlers when replies fail to load.
      }
    }

    try {
      mentionLookup = await resolveThreadMentionLookup(
        messages.map((message) => message.message),
        messages,
      )
    } catch {
      // Mention labels fall back to raw Discord markup.
    }

    const canonicalUrl = getThreadsCanonicalUrl(
      `/threads/${getThreadPublicId(thread)}`,
    )

    return {
      thread,
      messages,
      related,
      canonicalUrl,
      mentionLookup,
    }
  },
  head: ({ loaderData }) => {
    if (!loaderData?.thread) return {}

    const { thread, messages, canonicalUrl } = loaderData

    return {
      ...getThreadsThreadRouteHead(thread, canonicalUrl),
      scripts: [
        {
          type: 'application/ld+json',
          children: stringifyJsonLd(
            getDiscussionForumPageSchema({
              canonicalUrl,
              thread,
              messages,
            }),
          ),
        },
        {
          type: 'application/ld+json',
          children: stringifyJsonLd(
            getThreadsBreadcrumbSchema([
              { name: 'Threads', path: '/threads' },
              {
                name: thread.title,
                path: `/threads/${getThreadPublicId(thread)}`,
              },
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

  return (<ThreadDetailView {...pageData} />
    )
}
