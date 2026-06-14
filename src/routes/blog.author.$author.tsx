import { createFileRoute, notFound } from '@tanstack/react-router'
import { AuthorView } from '@/components/pages/blog/PostView'
import {
  getAllBlogAuthors,
  getBlogAuthor,
  getPostsForAuthor,
} from '@/lib/blog/content'
import { getBlogAuthorRouteMetaTags } from '@/lib/blog/route-meta'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'

export const Route = createFileRoute('/blog/author/$author')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  loader: async ({ context, params }) => {
    await marketingPageLoader(context.queryClient)

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
      meta: getBlogAuthorRouteMetaTags(loaderData.author),
    }
  },
  component: BlogAuthorPage,
})

function BlogAuthorPage() {
  const { author, posts, authors } = Route.useLoaderData()

  return (
    <MarketingPageShell>
      <AuthorView author={author} posts={posts} authors={authors} />
    </MarketingPageShell>
  )
}
