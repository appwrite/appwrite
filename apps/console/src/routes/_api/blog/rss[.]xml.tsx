import { createFileRoute } from '@tanstack/react-router'
import {
  getPostCategoryLabel,
  getPublicBlogPosts,
  resolveBlogAuthors,
} from '@/lib/blog/content'
import { BLOG_DEFAULT_DESCRIPTION } from '@/lib/blog/seo'
import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import { BLOG_RSS_PATH, buildRssFeed } from '@/lib/seo/rss'

const FEED_ITEM_LIMIT = 50

export const Route = createFileRoute('/_api/blog/rss.xml')({
  server: {
    handlers: {
      GET: async () => {
        const posts = getPublicBlogPosts().slice(0, FEED_ITEM_LIMIT)

        const feed = buildRssFeed({
          title: 'Appwrite Blog',
          link: `${MARKETING_SITE_ORIGIN}/blog`,
          description: BLOG_DEFAULT_DESCRIPTION,
          feedUrl: `${MARKETING_SITE_ORIGIN}${BLOG_RSS_PATH}`,
          items: posts.map((post) => ({
            title: post.title,
            link: `${MARKETING_SITE_ORIGIN}${post.href}`,
            description: post.description,
            date: post.date,
            authors: resolveBlogAuthors(post.author).map((author) => author.name),
            category: getPostCategoryLabel(post),
            imageUrl: post.cover
              ? `${MARKETING_SITE_ORIGIN}${post.cover}`
              : undefined,
          })),
        })

        return new Response(feed, {
          headers: {
            'Content-Type': 'application/rss+xml; charset=utf-8',
            'Cache-Control': 'public, max-age=3600, stale-while-revalidate=86400',
          },
        })
      },
    },
  },
})
