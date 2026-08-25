import { createFileRoute, notFound } from '@tanstack/react-router'
import { PostView } from '@/components/pages/blog/PostView'
import {
  getBlogMarkdownExport,
  getBlogPost,
  getPostCategoryLabel,
  getPrimaryPostCategorySlug,
  resolveBlogAuthors,
} from '@/lib/blog/content'
import {
  getBlogBreadcrumbSchema,
  getBlogFaqSchema,
  getBlogPostSchema,
} from '@/lib/blog/seo'
import { getBlogPostRouteMetaTags } from '@/lib/blog/route-meta'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { NOINDEX_ROBOTS_META, NOINDEX_ROBOTS_HEADER } from '@/lib/seo/indexing'
import { BLOG_RSS_PATH } from '@/lib/seo/rss'
import { trackServerPageview } from '@/lib/server-analytics'

export const Route = createFileRoute('/_marketing/blog/post/$slug')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ params, request, next }) => {
        const slug = params.slug
        if (!slug.endsWith('.md')) {
          return next()
        }

        const postSlug = slug.slice(0, -3)
        const markdown = getBlogMarkdownExport(postSlug)
        if (!markdown) {
          return new Response('Not found', { status: 404 })
        }

        trackServerPageview(request)

        return new Response(markdown, {
          headers: {
            'Content-Type': 'text/markdown; charset=utf-8',
            'Cache-Control': 'public, max-age=3600',
            // Drafts are only reachable with the blogDrafts flag on; keep them
            // out of search indexes even then.
            ...(getBlogPost(postSlug)?.draft
              ? { 'X-Robots-Tag': NOINDEX_ROBOTS_HEADER }
              : {}),
          },
        })
      },
    },
  },
  loader: async ({ context, params }) => {

    if (params.slug.endsWith('.md')) {
      throw notFound()
    }

    const post = getBlogPost(params.slug)
    if (!post) {
      throw notFound()
    }

    return { post }
  },
  head: ({ loaderData }) => {
    if (!loaderData?.post) return {}

    const siteOrigin = getRequestSiteOrigin()
    const seoOptions = { siteOrigin }
    const authors = resolveBlogAuthors(loaderData.post.author)
    const scripts = [
      {
        type: 'application/ld+json',
        children: JSON.stringify(getBlogPostSchema(loaderData.post, authors, seoOptions)),
      },
      {
        type: 'application/ld+json',
        children: JSON.stringify(
          getBlogBreadcrumbSchema([
            { name: 'Blog', path: '/blog' },
            {
              name: getPostCategoryLabel(loaderData.post),
              path: `/blog/category/${getPrimaryPostCategorySlug(loaderData.post)}`,
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
      meta: [
        ...getBlogPostRouteMetaTags(loaderData.post, authors, seoOptions),
        // Draft pages only render with the blogDrafts flag on; never index them.
        ...(loaderData.post.draft ? [NOINDEX_ROBOTS_META] : []),
      ],
      links: [
        {
          rel: 'alternate',
          type: 'application/rss+xml',
          title: 'Appwrite Blog',
          href: BLOG_RSS_PATH,
        },
        {
          rel: 'alternate',
          type: 'text/markdown',
          href: `${loaderData.post.href}.md`,
        },
      ],
      scripts,
    }
  },
  component: BlogPostPage,
})

function BlogPostPage() {
  const { post } = Route.useLoaderData()

  return (<PostView post={post} />
    )
}
