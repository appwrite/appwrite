import { createFileRoute } from '@tanstack/react-router'
import { AuthorsView } from '@/components/pages/threads/AuthorsView'
import { getAuthors } from '@/lib/threads/content'
import { getThreadsAuthorsRouteMetaTags } from '@/lib/threads/route-meta'
import {
  getThreadsAuthorsPageSchema,
  getThreadsBreadcrumbSchema,
} from '@/lib/threads/seo'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'

export const Route = createFileRoute('/threads/authors/')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
    return getAuthors()
  },
  head: ({ loaderData }) => ({
    meta: getThreadsAuthorsRouteMetaTags(),
    scripts: [
      {
        type: 'application/ld+json',
        children: JSON.stringify(
          getThreadsAuthorsPageSchema(loaderData?.authors ?? []),
        ),
      },
      {
        type: 'application/ld+json',
        children: JSON.stringify(
          getThreadsBreadcrumbSchema([
            { name: 'Threads', path: '/threads' },
            { name: 'Authors', path: '/threads/authors' },
          ]),
        ),
      },
    ],
  }),
  component: ThreadsAuthorsPage,
})

function ThreadsAuthorsPage() {
  const pageData = Route.useLoaderData()

  return (
    <MarketingPageShell>
      <AuthorsView {...pageData} />
    </MarketingPageShell>
  )
}
