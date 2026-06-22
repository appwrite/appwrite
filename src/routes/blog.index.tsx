import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/blog/View'
import { getBlogPostsPage } from '@/lib/blog/content'
import { getBlogIndexRouteMetaTags } from '@/lib/blog/route-meta'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'

const blogSearchSchema = z.object({
  search: z.string().optional(),
  category: z.string().optional(),
})

export const Route = createFileRoute('/blog/')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  validateSearch: blogSearchSchema,
  loader: async ({ context, location }) => {
    await marketingPageLoader(context.queryClient)
    const search = blogSearchSchema.parse(location.search)

    return getBlogPostsPage({
      page: 1,
      search: search.search,
      category: search.category,
    })
  },
  head: () => ({
    meta: getBlogIndexRouteMetaTags({ siteOrigin: getRequestSiteOrigin() }),
  }),
  component: BlogIndexPage,
})

function BlogIndexPage() {
  const pageData = Route.useLoaderData()
  const search = Route.useSearch()

  return (
    <MarketingPageShell>
      <View {...pageData} search={search} />
    </MarketingPageShell>
  )
}
