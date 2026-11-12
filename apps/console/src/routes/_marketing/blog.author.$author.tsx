import { createFileRoute, notFound } from '@tanstack/react-router'
import { AuthorView } from '@/components/pages/blog/PostView'
import {
  getAllBlogAuthors,
  getBlogAuthor,
  getPostsForAuthor,
} from '@/lib/blog/content'
import { getBlogAuthorRouteHead } from '@/lib/blog/route-meta'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'

export const Route = createFileRoute('/_marketing/blog/author/$author')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  loader: async ({ context, params }) => {

    const author = getBlogAuthor(params.author)
    if (!author) {
      throw notFound()
    }

    // Authors whose posts were all removed have no page.
    const posts = getPostsForAuthor(params.author)
    if (posts.length === 0) {
      throw notFound()
    }

    return {
      author,
      posts,
      authors: getAllBlogAuthors(),
    }
  },
  head: ({ loaderData }) => {
    if (!loaderData?.author) return {}
    return getBlogAuthorRouteHead(loaderData.author, {
      siteOrigin: getRequestSiteOrigin(),
    })
  },
  component: BlogAuthorPage,
})

function BlogAuthorPage() {
  const { author, posts, authors } = Route.useLoaderData()

  return (<AuthorView author={author} posts={posts} authors={authors} />
    )
}
