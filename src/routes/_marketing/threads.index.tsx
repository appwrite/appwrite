import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/threads/View'
import { getThreads } from '@/lib/threads/content'
import { getThreadsIndexRouteMetaTags } from '@/lib/threads/route-meta'
import { getThreadsIndexPageSchema } from '@/lib/threads/seo'
import { parseThreadsTags, threadsSearchSchema } from '@/lib/threads/search'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'

export const Route = createFileRoute('/_marketing/threads/')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  validateSearch: threadsSearchSchema,
  loader: async ({ context, location }) => {

    const search = threadsSearchSchema.parse(location.search)
    const tags = parseThreadsTags(search.tags)
    const q = search.q?.trim() || undefined

    const result = await getThreads({
      q,
      tags,
      allTags: true,
    })

    return {
      ...result,
      q: q ?? '',
      tags,
    }
  },
  head: () => ({
    meta: getThreadsIndexRouteMetaTags(),
    scripts: [
      {
        type: 'application/ld+json',
        children: JSON.stringify(getThreadsIndexPageSchema()),
      },
    ],
  }),
  component: ThreadsIndexPage,
})

function ThreadsIndexPage() {
  const pageData = Route.useLoaderData()

  return (<View {...pageData} />
    )
}
