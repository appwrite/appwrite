import { createFileRoute, notFound } from '@tanstack/react-router'
import { AuthorView } from '@/components/pages/blog/PostView'
import {
  getAllBlogAuthors,
  getBlogAuthor,
  getPostsForAuthor,
} from '@/lib/blog/content'
import { getBlogAuthorRouteMetaTags } from '@/lib/blog/route-meta'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'

export const Route = createFileRoute('/_marketing/blog/author/$author')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  loader: async ({ context, params }) => {

    const author = getBlogAuthor(params.author)
    if (!author) {
      throw notFound()
    }

    return {
      author,
      posts: getPostsForAuthor(params.author),
      authors: getAllBlogAuthors(),
    }
  },
  head: ({ loaderData }) => {
    if (!loaderData?.author) return {}
    return {
      meta: getBlogAuthorRouteMetaTags(loaderData.author, {
        siteOrigin: getRequestSiteOrigin(),
      }),
    }
  },
  component: BlogAuthorPage,
})

function BlogAuthorPage() {
  const { author, posts, authors } = Route.useLoaderData()

  return (<AuthorView author={author} posts={posts} authors={authors} />
    )
}
