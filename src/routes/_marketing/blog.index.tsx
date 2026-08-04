import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/blog/View'
import { getBlogPostsPage } from '@/lib/blog/content'
import { getBlogIndexRouteMetaTags } from '@/lib/blog/route-meta'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { BLOG_RSS_PATH } from '@/lib/seo/rss'

const blogSearchSchema = z.object({
  search: z.string().optional(),
  category: z.string().optional(),
})

export const Route = createFileRoute('/_marketing/blog/')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  validateSearch: blogSearchSchema,
  loader: async ({ context, location }) => {
    const search = blogSearchSchema.parse(location.search)

    return getBlogPostsPage({
      page: 1,
      search: search.search,
      category: search.category,
    })
  },
  head: () => ({
    meta: getBlogIndexRouteMetaTags({ siteOrigin: getRequestSiteOrigin() }),
    links: [
      {
        rel: 'alternate',
        type: 'application/rss+xml',
        title: 'Appwrite Blog',
        href: BLOG_RSS_PATH,
      },
    ],
  }),
  component: BlogIndexPage,
})

function BlogIndexPage() {
  const pageData = Route.useLoaderData()
  const search = Route.useSearch()

  return (<View {...pageData} search={search} />
    )
}
