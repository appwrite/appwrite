import { createFileRoute, notFound } from '@tanstack/react-router'
import { PostView } from '@/components/pages/blog/PostView'
import {
  getBlogPost,
  normalizeCategory,
  resolveBlogAuthors,
} from '@/lib/blog/content'
import {
  getBlogBreadcrumbSchema,
  getBlogFaqSchema,
  getBlogPostSchema,
} from '@/lib/blog/seo'
import { getBlogPostRouteMetaTags } from '@/lib/blog/route-meta'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'

export const Route = createFileRoute('/blog/post/$slug')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  loader: async ({ context, params }) => {
    await marketingPageLoader(context.queryClient)

    const post = getBlogPost(params.slug)
    if (!post) {
      throw notFound()
    }

    return { post }
  },
  head: ({ loaderData }) => {
    if (!loaderData?.post) return {}

    const authors = resolveBlogAuthors(loaderData.post.author)
    const scripts = [
      {
        type: 'application/ld+json',
        children: JSON.stringify(getBlogPostSchema(loaderData.post, authors)),
      },
      {
        type: 'application/ld+json',
        children: JSON.stringify(
          getBlogBreadcrumbSchema([
            { name: 'Blog', path: '/blog' },
            {
              name: loaderData.post.category.replace(/-/g, ' '),
              path: `/blog/category/${normalizeCategory(loaderData.post.category)}`,
            },
            { name: loaderData.post.title, path: loaderData.post.href },
          ]),
        ),
      },
    ]

    if (loaderData.post.faqs?.length) {
      scripts.push({
        type: 'application/ld+json',
        children: JSON.stringify(getBlogFaqSchema(loaderData.post.faqs)),
      })
    }

    return {
      meta: getBlogPostRouteMetaTags(loaderData.post),
      scripts,
    }
  },
  component: BlogPostPage,
})

function BlogPostPage() {
  const { post } = Route.useLoaderData()

  return (
    <MarketingPageShell>
      <PostView post={post} />
    </MarketingPageShell>
  )
}
