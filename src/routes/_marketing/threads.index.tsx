import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/threads/View'
import { getThreads } from '@/lib/threads/content'
import { getThreadsIndexRouteHead } from '@/lib/threads/route-meta'
import { getThreadsIndexPageSchema } from '@/lib/threads/seo'
import { parseThreadsTags, threadsSearchSchema } from '@/lib/threads/search'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { stringifyJsonLd } from '@/lib/seo/json-ld'

export const Route = createFileRoute('/_marketing/threads/')({
  ...marketingRouteLifetime,
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
    ...getThreadsIndexRouteHead(),
    scripts: [
      {
        type: 'application/ld+json',
        children: stringifyJsonLd(getThreadsIndexPageSchema()),
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
