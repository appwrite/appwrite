import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/blog/View'
import { getBlogPostsPage } from '@/lib/blog/content'
import { getBlogIndexRouteMetaTags } from '@/lib/blog/route-meta'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'

const blogSearchSchema = z.object({
  search: z.string().optional(),
  category: z.string().optional(),
})

export const Route = createFileRoute('/_marketing/blog/$page')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  validateSearch: blogSearchSchema,
  loader: async ({ context, params, location }) => {
    const search = blogSearchSchema.parse(location.search)

    const pageNumber = Number.parseInt(params.page, 10)
    if (!Number.isFinite(pageNumber) || pageNumber < 1) {
      throw redirect({ to: '/blog', replace: true })
    }

    if (pageNumber === 1) {
      throw redirect({
        to: '/blog',
        search,
        replace: true,
      })
    }

    return getBlogPostsPage({
      page: pageNumber,
      search: search.search,
      category: search.category,
    })
  },
  head: () => ({
    meta: getBlogIndexRouteMetaTags({ siteOrigin: getRequestSiteOrigin() }),
  }),
  component: BlogPaginatedPage,
})

function BlogPaginatedPage() {
  const pageData = Route.useLoaderData()
  const search = Route.useSearch()

  return (<View {...pageData} search={search} />
    )
}
