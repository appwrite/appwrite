import { createFileRoute, notFound, redirect } from '@tanstack/react-router'
import { CategoryView } from '@/components/pages/blog/PostView'
import {
  getAllBlogAuthors,
  getBlogCategory,
  getPostsForCategory,
} from '@/lib/blog/content'
import {
  normalizeCategorySlug,
  resolveCategorySlug,
} from '@/lib/blog/category-slugs'
import { getBlogCategoryRouteMetaTags } from '@/lib/blog/route-meta'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'

export const Route = createFileRoute('/_marketing/blog/categories/$category')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  beforeLoad: ({ params }) => {
    const resolved = resolveCategorySlug(params.category)
    if (resolved !== normalizeCategorySlug(params.category)) {
      throw redirect({
        to: '/blog/categories/$category',
        params: { category: resolved },
        replace: true,
      })
    }
  },
  loader: async ({ params }) => {
    const categorySlug = resolveCategorySlug(params.category)
    const category = getBlogCategory(categorySlug)
    if (!category) {
      throw notFound()
    }

    return {
      category,
      posts: getPostsForCategory(categorySlug),
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
