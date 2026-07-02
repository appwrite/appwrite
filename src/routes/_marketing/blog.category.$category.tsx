import { createFileRoute, notFound } from '@tanstack/react-router'
import { CategoryView } from '@/components/pages/blog/PostView'
import {
  getAllBlogAuthors,
  getBlogCategory,
  getPostsForCategory,
} from '@/lib/blog/content'
import { getBlogCategoryRouteMetaTags } from '@/lib/blog/route-meta'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'

export const Route = createFileRoute('/_marketing/blog/category/$category')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  loader: async ({ context, params }) => {

    const category = getBlogCategory(params.category)
    if (!category) {
      throw notFound()
    }

    return {
      category,
      posts: getPostsForCategory(params.category),
      authors: getAllBlogAuthors(),
    }
  },
  head: ({ loaderData }) => {
    if (!loaderData?.category) return {}
    return {
      meta: getBlogCategoryRouteMetaTags(loaderData.category, {
        siteOrigin: getRequestSiteOrigin(),
      }),
    }
  },
  component: BlogCategoryPage,
})

function BlogCategoryPage() {
  const { category, posts, authors } = Route.useLoaderData()

  return (<CategoryView category={category} posts={posts} authors={authors} />
    )
}
